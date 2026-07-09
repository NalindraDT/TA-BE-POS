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
            $paymentType = $notif->payment_type; 
            $orderId = $notif->order_id;
            $fraudStatus = $notif->fraud_status;

            // ✨ EKSTRAK KODE INVOICE ASLI
            $realInvoice = explode('_', $orderId)[0];

            // 3. Cari Transaksi di Database menggunakan Invoice Asli
            $model = new \App\Models\TransaksiModel();
            $transaksi = $model->where('kode_invoice', $realInvoice)->first();

            if (!$transaksi) {
                return $this->failNotFound('Transaksi tidak ditemukan.');
            }

            // ========================================================================
            // 🛡️ PERISAI ANTI-TABRAKAN (SOLUSI KASUS TUNAI VS EXPIRED MIDTRANS)
            // ========================================================================
            if ($transaksi['status_pembayaran'] === 'Lunas') {
                // Beri respon 200 OK ke Midtrans agar Midtrans berhenti mengirim notif,
                // tapi kita TIDAK merubah apapun di database kita.
                return $this->respond([
                    'status' => 200,
                    'message' => 'Notifikasi diabaikan karena transaksi ini sudah dilunasi secara sistem (via Tunai/Kasir).'
                ]);
            }

            // 4. Logika Perubahan Status (Sync dengan Dashboard Midtrans)
            $statusBaru = $transaksi['status_pembayaran'];

            if ($transactionStatus == 'settlement' || $transactionStatus == 'capture') {
                $statusBaru = 'Lunas';
            } else if ($transactionStatus == 'expire' || $transactionStatus == 'cancel' || $transactionStatus == 'deny') {
                $statusBaru = 'Batal';
            } else if ($transactionStatus == 'pending') {
                $statusBaru = 'Pending';
            }

            // 5. Eksekusi Update ke Database
            $metodeBaru = strtoupper(str_replace('_', ' ', $paymentType));

            // Jika transaksi bukan Tunai, update status dan metode Midtrans-nya
            if ($transaksi['metode_pembayaran'] !== 'Tunai') {
                $model->update($transaksi['id_transaksi'], [
                    'status_pembayaran' => $statusBaru,
                    'metode_pembayaran' => $metodeBaru 
                ]);
            } else {
                // Walaupun mustahil sampai ke baris ini berkat "Perisai" di atas, 
                // blok ini tetap bagus sebagai pengaman tambahan.
                $model->update($transaksi['id_transaksi'], [
                    'status_pembayaran' => $statusBaru
                ]);
            }

            // 6. Catat ke Log Aktivitas
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
