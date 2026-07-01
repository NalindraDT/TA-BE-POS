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

        $periode = $this->request->getGet('periode') ?? 'bulan';
        $id_kasir = $this->request->getGet('id_kasir');
        
        $status_pembayaran = $this->request->getGet('status');
        
        // ✨ SETUP PAGINATION
        $page   = (int) ($this->request->getGet('page') ?? 1);
        $limit  = (int) ($this->request->getGet('limit') ?? 10);
        $offset = ($page - 1) * $limit;

        $db = Database::connect();
        
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
            // KONTEN 1: HITUNG RINGKASAN REVENUE SHARING 
            // ==========================================================
            $builderRingkasan = $db->table('detail_transaksi')
                ->select('
                    SUM(detail_transaksi.subtotal) as total_omset,
                    SUM(detail_transaksi.subtotal * (kasir.persentase_komisi / 100)) as bagi_hasil_kasir
                ')
                ->join('transaksi', 'transaksi.id_transaksi = detail_transaksi.id_transaksi')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk')
                ->join('users as kasir', 'kasir.id_user = transaksi.id_user', 'left')
                ->where('produk.id_user', $id_owner)
                ->where($whereTanggal);

            if (!empty($id_kasir)) {
                $builderRingkasan->where('transaksi.id_user', $id_kasir);
            }

            if (!empty($status_pembayaran)) {
                // Jika dropdown filter status ditekan (misal Owner sengaja mau ngecek total uang ngadat/Pending)
                $builderRingkasan->where('transaksi.status_pembayaran', $status_pembayaran);
            } else {
                // JIKA DEFAULT (Semua Status), MAKA YANG DIHITUNG SEBAGAI OMSET HANYALAH YANG "LUNAS" SAJA!
                $builderRingkasan->where('transaksi.status_pembayaran', 'Lunas');
            }

            $queryRingkasan = $builderRingkasan->get()->getRowArray();

            $totalOmset     = (int)($queryRingkasan['total_omset'] ?? 0);
            $bagiHasilKasir = (int)($queryRingkasan['bagi_hasil_kasir'] ?? 0);
            $pemasukanOwner = $totalOmset - $bagiHasilKasir;

            // ==========================================================
            // KONTEN 2: RIWAYAT TRANSAKSI DENGAN PAGINATION
            // ==========================================================
            $builderRiwayat = $db->table('transaksi')
                // 🔥 UBAH total_harga MENJADI SUM(subtotal) agar nominal struk sesuai dengan barang si owner saja
                ->select('transaksi.id_transaksi, transaksi.kode_invoice, transaksi.nama_pelanggan, transaksi.status_pembayaran, transaksi.metode_pembayaran, transaksi.tanggal_transaksi, kasir.nama_lengkap as nama_kasir, SUM(detail_transaksi.subtotal) as total_harga_owner')
                ->join('detail_transaksi', 'detail_transaksi.id_transaksi = transaksi.id_transaksi')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk')
                ->join('users as kasir', 'kasir.id_user = transaksi.id_user', 'left')
                ->where('produk.id_user', $id_owner)
                ->where($whereTanggal)
                ->groupBy('transaksi.id_transaksi') 
                ->orderBy('transaksi.tanggal_transaksi', 'DESC');

            if (!empty($id_kasir)) {
                $builderRiwayat->where('transaksi.id_user', $id_kasir);
            }

            if (!empty($status_pembayaran)) {
                $builderRiwayat->where('transaksi.status_pembayaran', $status_pembayaran);
            }

            // Hitung total data untuk meta pagination
            $totalDataRiwayat = $builderRiwayat->countAllResults(false);
            $totalPages = ceil($totalDataRiwayat / $limit);

            // Eksekusi data dengan limit
            $riwayatTransaksi = $builderRiwayat->limit($limit, $offset)->get()->getResultArray();

            return $this->respond([
                'status'    => 200,
                'message'   => 'Laporan berhasil dimuat',
                'filter'    => [
                    'periode'  => $periode,
                    'id_kasir' => $id_kasir ?? 'Semua Kasir'
                ],
                'pagination' => [
                    'current_page' => $page,
                    'total_pages'  => $totalPages,
                    'total_data'   => $totalDataRiwayat
                ],
                'data'      => [
                    'ringkasan_keuangan' => [
                        'pemasukan_owner'  => $pemasukanOwner,
                        'total_omset'      => $totalOmset,
                        'bagi_hasil_kasir' => $bagiHasilKasir
                    ],
                    'riwayat_transaksi' => $riwayatTransaksi 
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }
}