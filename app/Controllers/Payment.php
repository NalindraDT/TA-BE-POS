<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\TransaksiModel;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// Panggil library Midtrans
use Midtrans\Config;
use Midtrans\Snap;

class Payment extends ResourceController
{
    protected $format = 'json';

    public function __construct()
    {
        // Inisialisasi Konfigurasi Midtrans
        Config::$serverKey = getenv('MIDTRANS_SERVER_KEY');
        // Set ke Development/Sandbox Environment (default). Set to true for Production Environment
        Config::$isProduction = getenv('MIDTRANS_IS_PRODUCTION') === 'true';
        // Set sanitization on (default)
        Config::$isSanitized = true;
        // Set 3DS transaction for credit card to true
        Config::$is3ds = true;
    }

    public function createTransaction()
    {
        // 1. Verifikasi Token Kasir (Keamanan Dasar)
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');
        
        $json = $this->request->getJSON();
        if (!$json || empty($json->total_harga)) {
            return $this->fail('Data transaksi tidak lengkap.', 400);
        }

        // Tangkap nama pelanggan dari Flutter (jika kosong, beri nilai default)
        $namaPelanggan = $json->nama_pelanggan ?? 'Pelanggan Umum';

        // 3. Buat Order ID Unik
        $orderId = 'INV-' . time();
        $grossAmount = $json->total_harga; 

        // 4. Siapkan Parameter untuk Midtrans
        $params = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $grossAmount,
            ],
            'customer_details' => [
                'first_name' => $namaPelanggan, // 👈 Nama ini akan muncul di layar Midtrans
                'last_name'  => '', // Dikosongkan tidak masalah
            ]
        ];

        try {
            // 5. Minta Snap Token ke Server Midtrans
            $snapToken = Snap::getSnapToken($params);

            // 6. Kembalikan Token ke Flutter
            return $this->respond([
                'status'  => 200,
                'message' => 'Snap Token berhasil didapatkan',
                'data'    => [
                    'order_id'   => $orderId,
                    'snap_token' => $snapToken
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }
    public function webhook()
    {
        // 1. Set Kunci Konfigurasi Midtrans
        Config::$serverKey = getenv('MIDTRANS_SERVER_KEY');
        Config::$isProduction = getenv('MIDTRANS_IS_PRODUCTION') === 'true';

        try {
            // 2. Tangkap Notifikasi dari Midtrans
            $notif = new \Midtrans\Notification();
            
            $transactionStatus = $notif->transaction_status;
            $paymentType = $notif->payment_type;
            $orderId = $notif->order_id;
            $fraudStatus = $notif->fraud_status;

            // 3. Cari Transaksi di Database berdasarkan Order ID (Kode Invoice)
            $model = new \App\Models\TransaksiModel();
            $transaksi = $model->where('kode_invoice', $orderId)->first();

            if (!$transaksi) {
                return $this->failNotFound('Transaksi tidak ditemukan.');
            }

            // 4. Logika Perubahan Status
            $statusBaru = $transaksi['status_pembayaran'];

            if ($transactionStatus == 'capture') {
                if ($paymentType == 'credit_card') {
                    if ($fraudStatus == 'challenge') {
                        $statusBaru = 'Pending';
                    } else {
                        $statusBaru = 'Lunas';
                    }
                }
            } else if ($transactionStatus == 'settlement') {
                // Settlement artinya uang sudah masuk ke kantong (Berhasil)
                $statusBaru = 'Lunas';
            } else if ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
                // Jika batal, ditolak, atau kadaluarsa (misal lewat dari 15 menit)
                $statusBaru = 'Batal';
            } else if ($transactionStatus == 'pending') {
                $statusBaru = 'Pending';
            }

            // 5. Eksekusi Update ke Database jika status berubah
            if ($statusBaru !== $transaksi['status_pembayaran']) {
                $model->update($transaksi['id_transaksi'], ['status_pembayaran' => $statusBaru]);

                // Opsional: Catat otomatis ke Log Aktivitas oleh Sistem
                $logModel = new \App\Models\LogAktivitasModel();
                $logModel->insert([
                    'id_user'    => $transaksi['id_user'], // Menggunakan ID Kasir yang buat transaksi
                    'aksi'       => 'PEMBAYARAN_OTOMATIS',
                    'keterangan' => 'Sistem Midtrans mengonfirmasi pelunasan tagihan via ' . strtoupper($paymentType) . ' untuk invoice: ' . $orderId
                ]);
            }

            // 6. Beri respon 200 OK ke Midtrans agar mereka berhenti mengirim notifikasi
            return $this->respond([
                'status' => 200, 
                'message' => 'Notifikasi Midtrans berhasil diproses'
            ]);

        } catch (\Exception $e) {
            return $this->fail('Gagal memproses notifikasi: ' . $e->getMessage(), 500);
        }
    }
}