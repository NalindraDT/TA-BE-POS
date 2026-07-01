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

    // ✨ Tambahkan $keyword = null di dalam kurung parameter
    public function getProdukLengkap($id_produk = null, $id_kategori = null, $id_owner = null, $role = null, $keyword = null)
    {
        $builder = $this->db->table('produk');
        $builder->select('produk.*, kategori.nama_kategori');
        $builder->join('kategori', 'kategori.id_kategori = produk.id_kategori');

        // 🛡️ LOGIKA VISIBILITAS CERDAS 
        // Jika yang akses adalah Kasir, sembunyikan produk yang is_active = 0
        if ($role === 'Kasir') {
            $builder->where('produk.is_active', 1);
        }

        // 🛡️ PERLINDUNGAN SOFT DELETE
        // Pastikan produk yang sudah dihapus (deleted_at tidak null) tidak ikut muncul
        $builder->where('produk.deleted_at', null);

        // ✨ LOGIKA PENCARIAN BERDASARKAN NAMA PRODUK
        if ($keyword != null) {
            $builder->like('produk.nama_produk', $keyword);
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

        // Urutkan berdasarkan yang paling baru dibuat
        $builder->orderBy('produk.created_at', 'DESC');

        return $builder->get()->getResultArray();
    }
}