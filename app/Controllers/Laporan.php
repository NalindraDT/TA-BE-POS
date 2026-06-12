<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\UserModel;
use Config\Database;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Laporan extends ResourceController
{
    protected $format = 'json';

    // Helper untuk mengambil data user dari Token JWT
    private function getLoggedInUser()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return null;
        
        try {
            $token   = explode(' ', $header)[1];
            $key     = getenv('JWT_SECRET');
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            
            $userModel = new UserModel();
            return $userModel->find($decoded->uid);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function index()
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid atau kedaluwarsa.');

        if ($user['role'] === 'Kasir') {
            return $this->failForbidden('Akses ditolak. Kasir tidak diizinkan melihat laporan ini.');
        }

        // Tangkap Parameter dari Flutter
        $periode = $this->request->getGet('periode') ?? 'bulan';
        $id_kasir = $this->request->getGet('id_kasir'); // Filter dinamis kasir
        
        $db = Database::connect();
        
        // Atur Filter Tanggal SQL
        $whereTanggal = "";
        if ($periode === 'hari') {
            $whereTanggal = "DATE(transaksi.tanggal_transaksi) = CURRENT_DATE()";
        } else if ($periode === 'minggu') {
            $whereTanggal = "transaksi.tanggal_transaksi >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } else {
            $whereTanggal = "transaksi.tanggal_transaksi >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }

        $id_owner = $user['id_user'];
        
        try {
            // ==========================================================
            // KONTEN 1: HITUNG RINGKASAN REVENUE SHARING (DINAMIS)
            // ==========================================================
            $builderRingkasan = $db->table('detail_transaksi')
                ->select('
                    SUM(detail_transaksi.subtotal) as total_omset,
                    SUM(detail_transaksi.subtotal * (kasir.persentase_komisi / 100)) as bagi_hasil_kasir
                ')
                ->join('transaksi', 'transaksi.id_transaksi = detail_transaksi.id_transaksi')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk')
                ->join('users as kasir', 'kasir.id_user = transaksi.id_user', 'left') // Ambil data persentase dari kasir pembuat transaksi
                ->where('produk.id_user', $id_owner)
                ->where($whereTanggal);

            // Jika Owner memilih filter Kasir tertentu di Flutter
            if (!empty($id_kasir)) {
                $builderRingkasan->where('transaksi.id_user', $id_kasir);
            }

            $queryRingkasan = $builderRingkasan->get()->getRowArray();

            $totalOmset     = (int)($queryRingkasan['total_omset'] ?? 0);
            $bagiHasilKasir = (int)($queryRingkasan['bagi_hasil_kasir'] ?? 0);
            $pemasukanOwner = $totalOmset - $bagiHasilKasir;

            // ==========================================================
            // KONTEN 2: STATISTIK PRODUK TERJUAL
            // ==========================================================
            $builderStatistik = $db->table('detail_transaksi')
                // 🛡️ TAMBAHKAN produk.gambar_produk DI DALAM SELECT
                ->select('produk.nama_produk, produk.gambar_produk, SUM(detail_transaksi.kuantitas_produk) as total_terjual, SUM(detail_transaksi.subtotal) as total_pendapatan')
                ->join('transaksi', 'transaksi.id_transaksi = detail_transaksi.id_transaksi')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk')
                ->where('produk.id_user', $id_owner)
                ->where($whereTanggal)
                ->groupBy('detail_transaksi.id_produk')
                ->orderBy('total_terjual', 'DESC');

            // Terapkan filter kasir ke statistik juga jika ada
            if (!empty($id_kasir)) {
                $builderStatistik->where('transaksi.id_user', $id_kasir);
            }

            $statistikProduk = $builderStatistik->get()->getResultArray();

            // Kembalikan Response sesuai dengan layout UI Card di Figma
            return $this->respond([
                'status'    => 200,
                'message'   => 'Laporan berhasil dimuat',
                'filter'    => [
                    'periode'  => $periode,
                    'id_kasir' => $id_kasir ?? 'Semua Kasir'
                ],
                'data'      => [
                    'ringkasan_keuangan' => [
                        'pemasukan_owner'  => $pemasukanOwner,
                        'total_omset'      => $totalOmset,
                        'bagi_hasil_kasir' => $bagiHasilKasir
                    ],
                    'statistik_produk' => $statistikProduk
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }
}