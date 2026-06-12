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
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();

        $transaksi = $transaksiModel->find($id);

        if (!$transaksi) {
            return $this->failNotFound('Transaksi tidak ditemukan.');
        } else {
            $detail = $detailModel->select('detail_transaksi.*, produk.nama_produk')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk', 'left')
                ->where('id_transaksi', $id)
                ->findAll();

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

        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $logModel = new \App\Models\LogAktivitasModel();

        $db = Database::connect();
        $db->transStart(); 

        try {
            $namaPelangganInput = $json->nama_pelanggan ?? 'Pelanggan Umum';
            $metodePembayaranInput = $json->metode_pembayaran ?? 'Tunai';

            $statusPembayaran = 'Pending';
            if (strtolower($metodePembayaranInput) === 'tunai') {
                $statusPembayaran = 'Lunas';
            }

            $dataTransaksi = [
                'id_user'           => $idKasir,
                'kode_invoice'      => $transaksiModel->generateInvoice(),
                'nama_pelanggan'    => $namaPelangganInput,
                'total_harga'       => $json->total_harga,
                'metode_pembayaran' => $metodePembayaranInput,
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
                'keterangan' => 'Membuat transaksi ' . $metodePembayaranInput . ' a.n ' . $namaPelangganInput . ' (' . $dataTransaksi['kode_invoice'] . ')'
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->fail('Gagal menyimpan transaksi ke database.', 500);
            }

            // =========================================================
            // 🚀 INTEGRASI MIDTRANS OTOMATIS JIKA BUKAN TUNAI
            // =========================================================
            $snapToken = null;
            $metodeMidtrans = ['online', 'qris', 'transfer', 'midtrans'];

            if (in_array(strtolower($metodePembayaranInput), $metodeMidtrans)) {
                Config::$serverKey = getenv('MIDTRANS_SERVER_KEY');
                Config::$isProduction = getenv('MIDTRANS_IS_PRODUCTION') === 'true';
                Config::$isSanitized = true;
                Config::$is3ds = true;

                $params = [
                    'transaction_details' => [
                        // Kita gunakan Invoice asli sebagai Order ID Midtrans!
                        'order_id'     => $dataTransaksi['kode_invoice'], 
                        'gross_amount' => $dataTransaksi['total_harga'],
                    ],
                    'customer_details' => [
                        'first_name' => $namaPelangganInput,
                    ]
                ];
                
                $snapToken = Snap::getSnapToken($params);
            }

            return $this->respondCreated([
                'status'       => 201,
                'message'      => 'Transaksi berhasil disimpan!',
                'kode_invoice' => $dataTransaksi['kode_invoice'],
                'id_transaksi' => $idTransaksiBaru,
                'snap_token'   => $snapToken // 👈 Flutter akan mengecek variabel ini
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
}