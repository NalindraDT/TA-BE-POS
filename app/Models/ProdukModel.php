<?php

namespace App\Models;

use CodeIgniter\Model;

class ProdukModel extends Model
{
    protected $table            = 'produk';
    protected $primaryKey       = 'id_produk';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields    = [
        'id_user', 
        'id_kategori', 
        'nama_produk', 
        'harga', 
        'gambar_produk'
    ];

    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    public function getProdukLengkap($id_produk = null)
    {
        $this->select('produk.*, kategori.nama_kategori, users.nama_lengkap as nama_pembuat');
        $this->join('kategori', 'kategori.id_kategori = produk.id_kategori', 'left');
        $this->join('users', 'users.id_user = produk.id_user', 'left');

        if ($id_produk != null) {
            return $this->where('produk.id_produk', $id_produk)->first();
        }

        return $this->findAll();
    }
}