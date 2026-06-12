<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\UserModel;
use Config\Database;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Dashboard extends ResourceController
{
    protected $format = 'json';

    // Helper Autentikasi JWT
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

        // Proteksi: Hanya Owner dan Admin yang butuh dashboard finansial ini
        if ($user['role'] === 'Kasir') {
            return $this->failForbidden('Akses ditolak. Kasir tidak memiliki akses ke dashboard ini.');
        }

        $db = Database::connect();
        $id_owner = $user['id_user'];
        
        try {
            // ==========================================================
            // KONTEN 1: METRIK HARI INI (CARD ORANYE)
            // ==========================================================
            $queryHariIni = $db->table('detail_transaksi')
                ->select('
                    SUM(detail_transaksi.subtotal) as pendapatan_kotor_hari_ini,
                    SUM(detail_transaksi.kuantitas_produk) as total_menu_terjual,
                    COUNT(DISTINCT transaksi.id_transaksi) as total_transaksi
                ')
                ->join('transaksi', 'transaksi.id_transaksi = detail_transaksi.id_transaksi')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk')
                ->where('produk.id_user', $id_owner)
                ->where('DATE(transaksi.tanggal_transaksi)', 'CURRENT_DATE()', false) // Ambil data hari ini saja
                ->get()
                ->getRowArray();

            // Pengecekan null jika hari ini belum ada yang laku sama sekali
            $pendapatanKotor = (int)($queryHariIni['pendapatan_kotor_hari_ini'] ?? 0);
            $totalMenu       = (int)($queryHariIni['total_menu_terjual'] ?? 0);
            $totalTransaksi  = (int)($queryHariIni['total_transaksi'] ?? 0);

            // ==========================================================
            // KONTEN 2: TOP 3 MENU PALING LARIS (SEPANJANG MASA)
            // ==========================================================
            $topMenu = $db->table('detail_transaksi')
                ->select('produk.nama_produk, produk.gambar_produk, SUM(detail_transaksi.kuantitas_produk) as total_terjual')
                ->join('transaksi', 'transaksi.id_transaksi = detail_transaksi.id_transaksi')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk')
                ->where('produk.id_user', $id_owner)
                // Sengaja TIDAK ADA filter tanggal agar menghitung sepanjang masa
                ->groupBy('detail_transaksi.id_produk')
                ->orderBy('total_terjual', 'DESC')
                ->limit(3) // Batasi hanya 3 juara teratas
                ->get()
                ->getResultArray();

            // ==========================================================
            // FORMATTING RESPONSE UNTUK FLUTTER
            // ==========================================================
            return $this->respond([
                'status'  => 200,
                'message' => 'Data dashboard berhasil dimuat',
                'data'    => [
                    'hari_ini' => [
                        'pendapatan_kotor' => $pendapatanKotor,
                        'total_transaksi'  => $totalTransaksi,
                        'menu_terjual'     => $totalMenu
                    ],
                    'top_menu' => $topMenu
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }
}