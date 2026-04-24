<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ProdukModel;

class Produk extends ResourceController
{
    protected $format = 'json';

    public function index()
    {
        $model = new ProdukModel();
        return $this->respond($model->getProdukLengkap());
    }

    public function show($id = null)
    {
        $model = new ProdukModel();
        $data = $model->getProdukLengkap($id);
        
        if ($data) {
            return $this->respond($data);
        } else {
            return $this->failNotFound('Data produk tidak ditemukan.');
        }
    }

    public function create()
    {
        $model = new ProdukModel();

        $rules = [
            'id_user'       => 'required|numeric',
            'id_kategori'   => 'required|numeric',
            'nama_produk'   => 'required|string',
            'harga'         => 'required|numeric',
            'gambar_produk' => 'uploaded[gambar_produk]|max_size[gambar_produk,2048]|is_image[gambar_produk]|mime_in[gambar_produk,image/jpg,image/jpeg,image/png]'
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        } else {
            $fileGambar = $this->request->getFile('gambar_produk');
            $namaGambar = $fileGambar->getRandomName();
            $fileGambar->move('uploads/produk', $namaGambar);

            $data = [
                'id_user'       => $this->request->getVar('id_user'),
                'id_kategori'   => $this->request->getVar('id_kategori'),
                'nama_produk'   => $this->request->getVar('nama_produk'),
                'harga'         => $this->request->getVar('harga'),
                'gambar_produk' => $namaGambar
            ];

            $model->insert($data);

            return $this->respondCreated([
                'status'  => 201,
                'message' => 'Produk berhasil ditambahkan.',
                'data'    => $data
            ]);
        }
    }

    public function update($id = null)
    {
        $model = new ProdukModel();
        $produkLama = $model->find($id);

        if (!$produkLama) {
            return $this->failNotFound('Data produk tidak ditemukan.');
        } else {
            $rules = [
                'id_kategori'   => 'permit_empty|numeric',
                'nama_produk'   => 'permit_empty|string',
                'harga'         => 'permit_empty|numeric',
                'gambar_produk' => 'max_size[gambar_produk,2048]|is_image[gambar_produk]|mime_in[gambar_produk,image/jpg,image/jpeg,image/png]'
            ];

            if (!$this->validate($rules)) {
                return $this->failValidationErrors($this->validator->getErrors());
            } else {
                $data = [
                    'id_kategori' => $this->request->getVar('id_kategori') ?? $produkLama['id_kategori'],
                    'nama_produk' => $this->request->getVar('nama_produk') ?? $produkLama['nama_produk'],
                    'harga'       => $this->request->getVar('harga') ?? $produkLama['harga'],
                ];

                $fileGambar = $this->request->getFile('gambar_produk');
                
                if ($fileGambar && $fileGambar->isValid() && !$fileGambar->hasMoved()) {
                    if ($produkLama['gambar_produk'] && file_exists('uploads/produk/' . $produkLama['gambar_produk'])) {
                        unlink('uploads/produk/' . $produkLama['gambar_produk']);
                    }
                    
                    $namaGambar = $fileGambar->getRandomName();
                    $fileGambar->move('uploads/produk', $namaGambar);
                    $data['gambar_produk'] = $namaGambar;
                }

                $model->update($id, $data);

                return $this->respond([
                    'status'  => 200,
                    'message' => 'Produk berhasil diubah.',
                    'data'    => $data
                ]);
            }
        }
    }

    public function delete($id = null)
    {
        $model = new ProdukModel();
        $produk = $model->find($id);

        if (!$produk) {
            return $this->failNotFound('Data produk tidak ditemukan.');
        } else {
            // Hapus gambar fisiknya dulu dari folder
            if ($produk['gambar_produk'] && file_exists('uploads/produk/' . $produk['gambar_produk'])) {
                unlink('uploads/produk/' . $produk['gambar_produk']);
            }

            // Baru hapus datanya dari database
            $model->delete($id);

            return $this->respondDeleted([
                'status'  => 200,
                'message' => 'Produk dan gambar berhasil dihapus.'
            ]);
        }
    }
}