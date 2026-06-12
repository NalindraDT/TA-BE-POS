<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\KategoriModel;
use App\Models\UserModel;
use App\Models\LogAktivitasModel; // 🚨 Panggil model Log
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Kategori extends ResourceController
{
    protected $format = 'json';

    // ==========================================
    // FUNGSI BANTUAN: AMBIL DATA USER DARI TOKEN
    // ==========================================
    private function getLoggedInUser()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return null;
        
        try {
            $token   = explode(' ', $header)[1];
            $key     = getenv('JWT_SECRET');
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            
            $userModel = new UserModel();
            return $userModel->find($decoded->uid);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function index()
    {
        $model = new KategoriModel();
        return $this->respond($model->findAll());
    }

    public function show($id = null)
    {
        $model = new KategoriModel();
        $data = $model->find($id);
        
        if ($data) {
            return $this->respond($data);
        } else {
            return $this->failNotFound('Data kategori tidak ditemukan.');
        }
    }

    public function create()
    {
        // 1. Verifikasi User
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $model = new KategoriModel();

        $rules = [
            'nama_kategori' => 'required|min_length[3]|is_unique[kategori.nama_kategori]',
            'deskripsi'     => 'permit_empty|string'
        ];

        $json = $this->request->getJSON();

        if (!$json) {
            return $this->fail('Body JSON kosong atau format salah.', 400);
        } else {
            $data = [
                'nama_kategori' => $json->nama_kategori ?? null,
                'deskripsi'     => $json->deskripsi ?? null
            ];

            if (!$this->validateData($data, $rules)) {
                return $this->failValidationErrors($this->validator->getErrors());
            } else {
                $model->insert($data);

                // 📝 CATAT LOG TAMBAH KATEGORI
                $logModel = new LogAktivitasModel();
                $logModel->insert([
                    'id_user'    => $user['id_user'],
                    'aksi'       => 'TAMBAH_KATEGORI',
                    'keterangan' => 'Menambahkan kategori baru: ' . $data['nama_kategori']
                ]);

                return $this->respondCreated([
                    'status'   => 201,
                    'messages' => ['success' => 'Kategori berhasil ditambahkan.'],
                    'data'     => $data
                ]);
            }
        }
    }

    public function update($id = null)
    {
        // 1. Verifikasi User
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $model = new KategoriModel();

        $rules = [
            'nama_kategori' => "required|min_length[3]|is_unique[kategori.nama_kategori,id_kategori,{$id}]",
            'deskripsi'     => 'permit_empty|string'
        ];

        $json = $this->request->getJSON();
        
        if (!$json) {
            return $this->fail('Body JSON kosong atau format salah.', 400);
        } else {
            // Ambil data lama dulu untuk keperluan pencatatan log jika dibutuhkan
            $kategoriLama = $model->find($id);

            if (!$kategoriLama) {
                return $this->failNotFound('Data kategori tidak ditemukan.');
            } else {
                $data = [
                    'nama_kategori' => $json->nama_kategori ?? null,
                    'deskripsi'     => $json->deskripsi ?? null
                ];

                if (!$this->validateData($data, $rules)) {
                    return $this->failValidationErrors($this->validator->getErrors());
                } else {
                    $model->update($id, $data);

                    // 📝 CATAT LOG UBAH KATEGORI
                    $logModel = new LogAktivitasModel();
                    $logModel->insert([
                        'id_user'    => $user['id_user'],
                        'aksi'       => 'UPDATE_KATEGORI',
                        'keterangan' => 'Mengubah data kategori dari ' . $kategoriLama['nama_kategori'] . ' menjadi ' . $data['nama_kategori']
                    ]);

                    return $this->respond([
                        'status'   => 200,
                        'messages' => ['success' => 'Kategori berhasil diubah.'],
                        'data'     => $data
                    ]);
                }
            }
        }
    }

    public function delete($id = null)
    {
        // 1. Verifikasi User
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $model = new KategoriModel();
        
        // Ambil data kategori sebelum dihapus untuk disimpan namanya di Log
        $kategori = $model->find($id);

        if (!$kategori) {
            return $this->failNotFound('Data kategori tidak ditemukan.');
        } else {
            try {
                // Mencoba menghapus kategori
                $model->delete($id);

                // 📝 CATAT LOG HAPUS KATEGORI
                $logModel = new LogAktivitasModel();
                $logModel->insert([
                    'id_user'    => $user['id_user'],
                    'aksi'       => 'HAPUS_KATEGORI',
                    'keterangan' => 'Menghapus kategori: ' . $kategori['nama_kategori']
                ]);

                return $this->respondDeleted([
                    'status'   => 200,
                    'messages' => ['success' => 'Kategori berhasil dihapus.']
                ]);
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
                // Menangkap error dari MySQL (terutama Error 1451 RESTRICT)
                $errorCode = $e->getCode();
                
                if ($errorCode == 1451) {
                    return $this->fail('Gagal menghapus. Kategori ini masih digunakan oleh satu atau beberapa produk.', 409); // 409 Conflict
                }

                // Jika error lain yang tidak terduga
                return $this->fail('Terjadi kesalahan pada database.', 500);
            }
        }
    }
}