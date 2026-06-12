<?php

namespace App\Models;

use CodeIgniter\Model;

class ProdukModel extends Model
{
    protected $table            = 'produk';
    protected $primaryKey       = 'id_produk';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $deletedField     = 'deleted_at';
    protected $allowedFields    = [
        'id_user',
        'id_kategori',
        'nama_produk',
        'harga',
        'gambar_produk',
        'is_active'
    ];

    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    // Tambahkan $role = null di dalam kurung parameter
    public function getProdukLengkap($id_produk = null, $id_kategori = null, $id_owner = null, $role = null)
    {
        $builder = $this->db->table('produk');
        $builder->select('produk.*, kategori.nama_kategori');
        $builder->join('kategori', 'kategori.id_kategori = produk.id_kategori');

        // 🛡️ LOGIKA VISIBILITAS CERDAS 
        // Jika yang akses adalah Kasir, sembunyikan produk yang is_active = 0
        // Jika yang akses Owner/Admin, kode ini dilewati (semua produk muncul)
        if ($role === 'Kasir') {
            $builder->where('produk.is_active', 1);
        }

        if ($id_produk != null) {
            $builder->where('produk.id_produk', $id_produk);
            return $builder->get()->getRowArray();
        }

        if ($id_kategori != null) {
            $builder->where('produk.id_kategori', $id_kategori);
        }

        if ($id_owner != null) {
            $builder->where('produk.id_user', $id_owner);
        }

        return $builder->get()->getResultArray();
    }
}
