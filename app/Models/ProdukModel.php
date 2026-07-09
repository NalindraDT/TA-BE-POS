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
        
        // ✨ UBAH: Tambahkan pengambilan nama owner dari tabel users
        $builder->select('produk.*, kategori.nama_kategori, users.nama_lengkap as nama_owner');
        
        // Pastikan pakai 'left' join untuk berjaga-jaga
        $builder->join('kategori', 'kategori.id_kategori = produk.id_kategori', 'left');
        
        // ✨ TAMBAHAN: Join ke tabel users untuk mengecek status sang pembuat/owner
        $builder->join('users', 'users.id_user = produk.id_user', 'left');

        // 🛡️ LOGIKA VISIBILITAS CERDAS 
        // Jika yang akses adalah Kasir, lakukan filter ketat
        if ($role === 'Kasir') {
            // 1. Produknya sendiri harus aktif
            $builder->where('produk.is_active', 1);
            
            // ✨ 2. Owner-nya harus berstatus aktif (tidak dinonaktifkan Admin)
            $builder->where('users.is_active', 1);
            
            // ✨ 3. Owner-nya belum dihapus (terlindungi dari soft-delete user)
            $builder->where('users.deleted_at IS NULL');
        }

        // 🛡️ PERLINDUNGAN SOFT DELETE PRODUK
        // Pastikan produk yang sudah dihapus (deleted_at tidak null) tidak ikut muncul
        $builder->where('produk.deleted_at IS NULL');

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