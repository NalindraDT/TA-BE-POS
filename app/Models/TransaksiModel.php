<?php

namespace App\Models;

use CodeIgniter\Model;

class TransaksiModel extends Model
{
    protected $table            = 'transaksi';
    protected $primaryKey       = 'id_transaksi';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields    = [
        'id_user',
        'kode_invoice',
        'nama_pelanggan',
        'total_harga',
        'metode_pembayaran',
        'status_pembayaran',
        'tanggal_transaksi'
    ];

    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    public function generateInvoice()
    {
        $tanggal = date('Ymd');

        $lastTransaksi = $this->like('kode_invoice', 'INV-' . $tanggal, 'after')
            ->orderBy('id_transaksi', 'DESC')
            ->first();

        if ($lastTransaksi) {
            $urutan = (int) substr($lastTransaksi['kode_invoice'], -3);
            $urutan++;
        } else {
            $urutan = 1;
        }

        return 'INV-' . $tanggal . '-' . sprintf('%03s', $urutan);
    }
}
