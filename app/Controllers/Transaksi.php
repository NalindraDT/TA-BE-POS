<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use Config\Database;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Midtrans\Config;
use Midtrans\Snap;

class Transaksi extends ResourceController
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
            
            $userModel = new \App\Models\UserModel();
            return $userModel->find($decoded->uid);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function index()
    {
        $model = new TransaksiModel();

        // 1. Tangkap parameter 'status' dari request GET (misal: ?status=pending)
        $statusInput = $this->request->getGet('status');

        // 2. Jika ada parameter status, pasang filter (Query Builder)
        if (!empty($statusInput)) {
            // Kita gunakan ucfirst() dan strtolower() agar aman, 
            // misal Flutter kirim 'pending' atau 'PENDING', akan diubah jadi 'Pending'
            $statusFormat = ucfirst(strtolower($statusInput));
            $model->where('status_pembayaran', $statusFormat);
        }

        // 3. Ambil data yang sudah difilter (atau semua data jika tidak ada filter), urutkan dari yang terbaru
        $data = $model->orderBy('created_at', 'DESC')->findAll();

        // 4. Kembalikan dengan format JSON yang terstruktur
        return $this->respond([
            'status'  => 200,
            'message' => 'Daftar transaksi berhasil dimuat.',
            'filter'  => $statusInput ?? 'Semua Transaksi', // Untuk memudahkan debugging
            'data'    => $data
        ]);
    }

    public function show($id = null)
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();

        $transaksi = $transaksiModel->find($id);

        if (!$transaksi) {
            return $this->failNotFound('Transaksi tidak ditemukan.');
        } else {
            $builder = $detailModel->select('detail_transaksi.*, produk.nama_produk')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk', 'left')
                ->where('id_transaksi', $id);

            if ($user['role'] === 'Owner') {
                $builder->where('produk.id_user', $user['id_user']);
            }

            $detail = $builder->findAll();

            $totalHargaOwner = 0;
            foreach ($detail as $d) {
                $totalHargaOwner += $d['subtotal'];
            }

            // Sisipkan variabel baru agar Flutter tidak bingung
            $transaksi['total_harga_owner'] = $totalHargaOwner;

            return $this->respond([
                'transaksi' => $transaksi,
                'detail'    => $detail
            ]);
        }
    }

    public function create()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');

        $token   = explode(' ', $header)[1];
        $key     = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $idKasir = $decoded->uid;

        $json = $this->request->getJSON();

        if (!$json || empty($json->details)) {
            return $this->fail('Keranjang belanja kosong atau data tidak valid.', 400);
        }

        // ✨ 1. TANGKAP WAKTU PEMBAYARAN DAN VALIDASI NAMA
        $waktuPembayaran = $json->waktu_pembayaran ?? 'sekarang'; // 'sekarang' atau 'nanti'
        $namaPelangganInput = $json->nama_pelanggan ?? '';

        if ($waktuPembayaran === 'nanti' && trim($namaPelangganInput) === '') {
            return $this->fail('Nama pelanggan WAJIB diisi jika pesanan disimpan untuk dibayar nanti.', 400);
        }

        // Jika tidak diisi (dan bayar sekarang), beri default
        if (trim($namaPelangganInput) === '') {
            $namaPelangganInput = 'Pelanggan Umum';
        }

        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $logModel = new \App\Models\LogAktivitasModel();

        $db = Database::connect();
        $db->transStart();

        try {
            $metodePembayaranInput = $json->metode_pembayaran ?? 'Tunai';

            // ✨ 2. TENTUKAN STATUS BERDASARKAN WAKTU PEMBAYARAN
            $statusPembayaran = 'Pending';
            if ($waktuPembayaran === 'sekarang' && strtolower($metodePembayaranInput) === 'tunai') {
                $statusPembayaran = 'Lunas';
            }

            $dataTransaksi = [
                'id_user'           => $idKasir,
                'kode_invoice'      => $transaksiModel->generateInvoice(),
                'nama_pelanggan'    => $namaPelangganInput,
                'total_harga'       => $json->total_harga,
                'uang_diterima'     => $json->uang_diterima ?? 0,
                'kembalian'         => $json->kembalian ?? 0,
                'metode_pembayaran' => ($waktuPembayaran === 'nanti') ? 'Belum Dipilih' : $metodePembayaranInput,
                'status_pembayaran' => $statusPembayaran,
                'tanggal_transaksi' => date('Y-m-d H:i:s')
            ];

            $transaksiModel->insert($dataTransaksi);
            $idTransaksiBaru = $transaksiModel->getInsertID();

            $dataDetailSiapInsert = [];
            foreach ($json->details as $item) {
                $dataDetailSiapInsert[] = [
                    'id_transaksi'     => $idTransaksiBaru,
                    'id_produk'        => $item->id_produk,
                    'kuantitas_produk' => $item->kuantitas_produk,
                    'harga_transaksi'  => $item->harga_transaksi,
                    'subtotal'         => $item->subtotal
                ];
            }

            $detailModel->insertBatch($dataDetailSiapInsert);

            $logModel->insert([
                'id_user'    => $idKasir,
                'aksi'       => 'BUAT_TRANSAKSI',
                'keterangan' => 'Membuat transaksi (Status: '.$statusPembayaran.') a.n ' . $namaPelangganInput . ' (' . $dataTransaksi['kode_invoice'] . ')'
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Gagal menyimpan transaksi ke database.', 500);
            }

            // ✨ 3. PANGGIL MIDTRANS HANYA JIKA "BAYAR SEKARANG" DAN BUKAN TUNAI
            $snapToken = null;
            $metodeMidtrans = ['online', 'qris', 'transfer', 'midtrans'];

            if ($waktuPembayaran === 'sekarang' && in_array(strtolower($metodePembayaranInput), $metodeMidtrans)) {
                Config::$serverKey = getenv('MIDTRANS_SERVER_KEY');
                Config::$isProduction = getenv('MIDTRANS_IS_PRODUCTION') === 'true';
                Config::$isSanitized = true;
                Config::$is3ds = true;

                $params = [
                    'transaction_details' => [
                        'order_id'     => $dataTransaksi['kode_invoice'],
                        'gross_amount' => $dataTransaksi['total_harga'],
                    ],
                    'customer_details' => [
                        'first_name' => $namaPelangganInput,
                    ]
                ];

                $snapToken = Snap::getSnapToken($params);

                $transaksiModel->update($idTransaksiBaru, [
                    'snap_token' => $snapToken
                ]);
            }

            return $this->respondCreated([
                'status'       => 201,
                'message'      => 'Transaksi berhasil disimpan!',
                'kode_invoice' => $dataTransaksi['kode_invoice'],
                'id_transaksi' => $idTransaksiBaru,
                'snap_token'   => $snapToken
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            return $this->fail($e->getMessage(), 400);
        }
    }
    public function pelunasanManual($id = null)
    {
        // 1. Verifikasi Kasir yang mengeksekusi pelunasan
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');

        $token   = explode(' ', $header)[1];
        $key     = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $idKasir = $decoded->uid;

        $model = new TransaksiModel();
        $transaksi = $model->find($id);

        if (!$transaksi) {
            return $this->failNotFound('Transaksi tidak ditemukan.');
        }

        // 2. Cek apakah transaksi memang belum lunas
        if ($transaksi['status_pembayaran'] === 'Lunas') {
            return $this->fail('Transaksi ini sudah lunas sebelumnya.', 400);
        }

        // 3. Eksekusi Update Status
        $model->update($id, ['status_pembayaran' => 'Lunas']);

        // 4. Catat Log Aktivitas (Sangat Penting untuk Audit!)
        $logModel = new \App\Models\LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $idKasir,
            'aksi'       => 'PELUNASAN_MANUAL',
            'keterangan' => 'Menerima pelunasan uang tunai untuk invoice: ' . $transaksi['kode_invoice']
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Transaksi berhasil dilunasi!',
            'data'    => [
                'kode_invoice' => $transaksi['kode_invoice'],
                'status_baru'  => 'Lunas'
            ]
        ]);
    }
    public function riwayat()
    {
        // 1. Verifikasi User
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');

        $token   = explode(' ', $header)[1];
        $key     = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $role    = $decoded->role;
        $idKasir = $decoded->uid;

        $model = new TransaksiModel();

        // 2. Tangkap parameter filter & Pagination dari Flutter
        $tanggal = $this->request->getGet('tanggal');
        $status  = $this->request->getGet('status');

        // ✨ SETUP PAGINATION: Default Halaman 1, Tampilkan 10 Data per Halaman
        $page   = (int) ($this->request->getGet('page') ?? 1);
        $limit  = (int) ($this->request->getGet('limit') ?? 10);
        $offset = ($page - 1) * $limit;

        // 3. Bangun Query
        $builder = $model->builder();
        $builder->select('transaksi.*, users.nama_lengkap as nama_kasir');
        $builder->join('users', 'users.id_user = transaksi.id_user', 'left');

        if ($role === 'Kasir') {
            $builder->where('transaksi.id_user', $idKasir);
        }

        // Filter tanggal
        if (!empty($tanggal)) {
            $builder->like('transaksi.tanggal_transaksi', $tanggal, 'after');
        }

        // Filter status
        if (!empty($status)) {
            $builder->where('transaksi.status_pembayaran', ucfirst(strtolower($status)));
        }

        // ✨ HITUNG TOTAL DATA SEBELUM DI-LIMIT (Untuk Informasi Pagination Frontend)
        // Parameter 'false' sangat penting agar kondisi where tidak di-reset oleh CI4
        $totalData  = $builder->countAllResults(false);
        $totalPages = ceil($totalData / $limit);

        // 4. Eksekusi Query dengan Limit & Offset
        $builder->orderBy('transaksi.tanggal_transaksi', 'DESC');
        $builder->limit($limit, $offset);
        $data = $builder->get()->getResultArray();

        // 5. Kembalikan Response Terstruktur
        return $this->respond([
            'status'  => 200,
            'message' => 'Riwayat transaksi berhasil dimuat.',
            'pagination' => [
                'current_page' => $page,
                'limit'        => $limit,
                'total_data'   => $totalData,
                'total_pages'  => $totalPages,
                'has_next'     => $page < $totalPages // Memudahkan Flutter cek halaman selanjutnya
            ],
            'data'    => $data
        ]);
    }
    public function lanjutkanPembayaran($id = null)
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');

        $token   = explode(' ', $header)[1];
        $key     = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $idKasir = $decoded->uid;

        $json = $this->request->getJSON();
        if (!$json || empty($json->details)) {
            return $this->fail('Keranjang belanja kosong atau data tidak valid.', 400);
        }

        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $logModel = new \App\Models\LogAktivitasModel();

        $transaksiLama = $transaksiModel->find($id);
        if (!$transaksiLama) {
            return $this->failNotFound('Transaksi tidak ditemukan.');
        }

        if ($transaksiLama['status_pembayaran'] === 'Lunas') {
            return $this->fail('Transaksi sudah lunas, tidak dapat diubah.', 400);
        }

        $db = Database::connect();
        $db->transStart();

        try {
            $namaPelangganInput = $json->nama_pelanggan ?? $transaksiLama['nama_pelanggan'];
            $metodePembayaranInput = $json->metode_pembayaran ?? 'Tunai';

            $statusPembayaran = 'Pending';
            if (strtolower($metodePembayaranInput) === 'tunai') {
                $statusPembayaran = 'Lunas';
            }

            $dataUpdate = [
                'nama_pelanggan'    => $namaPelangganInput,
                'total_harga'       => $json->total_harga,
                'uang_diterima'     => $json->uang_diterima ?? 0,
                'kembalian'         => $json->kembalian ?? 0,
                'metode_pembayaran' => $metodePembayaranInput,
                'status_pembayaran' => $statusPembayaran,
            ];
            $transaksiModel->update($id, $dataUpdate);

            $db->table('detail_transaksi')->where('id_transaksi', $id)->delete();

            $dataDetailSiapInsert = [];
            foreach ($json->details as $item) {
                $dataDetailSiapInsert[] = [
                    'id_transaksi'     => $id,
                    'id_produk'        => $item->id_produk,
                    'kuantitas_produk' => $item->kuantitas_produk,
                    'harga_transaksi'  => $item->harga_transaksi,
                    'subtotal'         => $item->subtotal
                ];
            }
            $detailModel->insertBatch($dataDetailSiapInsert);

            $logModel->insert([
                'id_user'    => $idKasir,
                'aksi'       => 'LANJUTKAN_PEMBAYARAN',
                'keterangan' => 'Memproses pelunasan invoice: ' . $transaksiLama['kode_invoice'] . ' via ' . $metodePembayaranInput
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Gagal memperbarui transaksi di database.', 500);
            }

            $snapToken = null;
            $metodeMidtrans = ['online', 'qris', 'transfer', 'midtrans'];

            if (in_array(strtolower($metodePembayaranInput), $metodeMidtrans)) {
                Config::$serverKey = getenv('MIDTRANS_SERVER_KEY');
                Config::$isProduction = getenv('MIDTRANS_IS_PRODUCTION') === 'true';
                Config::$isSanitized = true;
                Config::$is3ds = true;

                // ✨ SOLUSI KONFLIK MIDTRANS (Gunakan underscore + timestamp agar unik di mata Midtrans)
                // Contoh: INV-20260706-001_1718293812
                $midtransOrderId = $transaksiLama['kode_invoice'] . '_' . time();

                $params = [
                    'transaction_details' => [
                        'order_id'     => $midtransOrderId, 
                        'gross_amount' => $json->total_harga,
                    ],
                    'customer_details' => [
                        'first_name' => $namaPelangganInput,
                    ]
                ];

                $snapToken = Snap::getSnapToken($params);
                
                // Simpan token baru ke database
                $transaksiModel->update($id, ['snap_token' => $snapToken]);
            }

            return $this->respond([
                'status'       => 200,
                'message'      => 'Pembayaran berhasil diproses!',
                'kode_invoice' => $transaksiLama['kode_invoice'],
                'id_transaksi' => $id,
                'snap_token'   => $snapToken
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            return $this->fail($e->getMessage(), 400);
        }
    }
    public function cancelOrRefund($id = null)
    {
        // 1. Verifikasi User (Kasir yang mengeksekusi)
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');

        $token   = explode(' ', $header)[1];
        $key     = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $idKasir = $decoded->uid;

        $model = new TransaksiModel();
        $transaksi = $model->find($id);

        if (!$transaksi) {
            return $this->failNotFound('Transaksi tidak ditemukan.');
        }

        // 2. Tentukan Logika Status
        $statusLama = $transaksi['status_pembayaran'];
        $statusBaru = '';
        $aksiLog = '';
        $keteranganLog = '';

        if ($statusLama === 'Pending') {
            // Jika belum dibayar lalu dibatalkan -> BATAL
            $statusBaru = 'Batal';
            $aksiLog = 'BATAL_TRANSAKSI';
            $keteranganLog = 'Membatalkan transaksi yang belum dibayar untuk invoice: ' . $transaksi['kode_invoice'];
        } else if ($statusLama === 'Lunas') {
            // Jika sudah terbayar lalu dibatalkan -> REFUND
            $statusBaru = 'Refund';
            $aksiLog = 'REFUND_TRANSAKSI';
            $keteranganLog = 'Melakukan refund (pengembalian dana) untuk transaksi lunas: ' . $transaksi['kode_invoice'];
        } else {
            // Tolak jika status sudah Batal atau Refund
            return $this->fail('Transaksi ini sudah berstatus ' . $statusLama . ' dan tidak dapat diubah lagi.', 400);
        }

        // 3. Eksekusi Update ke Database
        $model->update($id, ['status_pembayaran' => $statusBaru]);

        // 4. Catat Log Aktivitas (Wajib untuk audit Owner)
        $logModel = new \App\Models\LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $idKasir,
            'aksi'       => $aksiLog,
            'keterangan' => $keteranganLog
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Transaksi berhasil diubah menjadi ' . $statusBaru . '!',
            'data'    => [
                'kode_invoice' => $transaksi['kode_invoice'],
                'status_baru'  => $statusBaru
            ]
        ]);
    }
}
