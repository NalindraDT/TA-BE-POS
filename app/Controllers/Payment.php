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

    public function webhook()
    {
        // 1. Set Kunci Konfigurasi Midtrans
        Config::$serverKey = getenv('MIDTRANS_SERVER_KEY');
        Config::$isProduction = getenv('MIDTRANS_IS_PRODUCTION') === 'true';

        try {
            // 2. Tangkap Notifikasi dari Midtrans
            $notif = new \Midtrans\Notification();

            $transactionStatus = $notif->transaction_status;
            $paymentType = $notif->payment_type; // Isinya: 'qris', 'bank_transfer', 'echannel', dll
            $orderId = $notif->order_id;
            $fraudStatus = $notif->fraud_status;

            // 3. Cari Transaksi di Database berdasarkan Order ID
            $model = new \App\Models\TransaksiModel();
            $transaksi = $model->where('kode_invoice', $orderId)->first();

            if (!$transaksi) {
                return $this->failNotFound('Transaksi tidak ditemukan.');
            }

            // 4. Logika Perubahan Status (Sync dengan Dashboard Midtrans)
            $statusBaru = $transaksi['status_pembayaran'];

            if ($transactionStatus == 'settlement' || $transactionStatus == 'capture') {
                $statusBaru = 'Lunas';
            } else if ($transactionStatus == 'expire') {
                $statusBaru = 'Expired'; // 👈 Mengikuti status Expired Midtrans
            } else if ($transactionStatus == 'cancel' || $transactionStatus == 'deny') {
                $statusBaru = 'Batal';
            } else if ($transactionStatus == 'pending') {
                $statusBaru = 'Pending';
            }

            // 5. Rapikan Nama Metode Pembayaran dari Midtrans
            // Contoh: 'bank_transfer' -> 'BANK TRANSFER', 'qris' -> 'QRIS'
            $metodeBaru = strtoupper(str_replace('_', ' ', $paymentType));

            // 6. Eksekusi Update ke Database (Update Status DAN Metode)
            // Jika transaksi bukan Tunai, timpa metode pembayarannya dengan data akurat dari Midtrans
            if ($transaksi['metode_pembayaran'] !== 'Tunai') {
                $model->update($transaksi['id_transaksi'], [
                    'status_pembayaran' => $statusBaru,
                    'metode_pembayaran' => $metodeBaru
                ]);
            } else {
                // Jika aslinya Tunai, cukup update statusnya saja (jaga-jaga)
                $model->update($transaksi['id_transaksi'], [
                    'status_pembayaran' => $statusBaru
                ]);
            }

            // 7. Catat ke Log Aktivitas
            if ($statusBaru !== $transaksi['status_pembayaran']) {
                $logModel = new \App\Models\LogAktivitasModel();
                $logModel->insert([
                    'id_user'    => $transaksi['id_user'],
                    'aksi'       => 'UPDATE_STATUS_MIDTRANS',
                    'keterangan' => "Midtrans mengonfirmasi status menjadi $statusBaru via $metodeBaru untuk invoice: $orderId"
                ]);
            }

            return $this->respond([
                'status' => 200,
                'message' => 'Notifikasi Midtrans berhasil diproses'
            ]);
        } catch (\Exception $e) {
            return $this->fail('Gagal memproses notifikasi: ' . $e->getMessage(), 500);
        }
    }
}
