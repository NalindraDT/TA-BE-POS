<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ProdukModel;
use App\Models\UserModel;
use App\Models\LogAktivitasModel; // 🚨 Wajib dipanggil untuk rekam jejak
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Produk extends ResourceController
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

    // ==========================================
    // 1. TAMPILKAN PRODUK (INDEX)
    // ==========================================
    public function index()
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $model = new ProdukModel();
        
        // 👈 Tangkap parameter filter & pencarian dari Frontend
        $id_kategori = $this->request->getGet('id_kategori');
        $keyword     = $this->request->getGet('keyword'); // ✨ INI TAMBAHANNYA
        
        $id_owner = ($user['role'] === 'Owner') ? $user['id_user'] : null;
        $role_pengakses = $user['role']; 

        // 👈 Lempar variabel $keyword sebagai parameter ke-5
        $data = $model->getProdukLengkap(null, $id_kategori, $id_owner, $role_pengakses, $keyword);

        if (empty($data)) {
            return $this->respond([
                'status'  => 200,
                'message' => 'Belum ada data produk atau produk tidak ditemukan.',
                'data'    => []
            ]);
        }

        return $this->respond([
            'status'  => 200,
            'message' => 'Data produk berhasil dimuat.',
            'data'    => $data
        ]);
    }

    // ==========================================
    // 2. TAMPILKAN 1 PRODUK (SHOW)
    // ==========================================
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

    // ==========================================
    // 3. TAMBAH PRODUK BARU (CREATE)
    // ==========================================
    public function create()
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        if ($user['role'] === 'Kasir') {
            return $this->failForbidden('Kasir tidak diizinkan menambah produk.');
        }

        $model = new ProdukModel();

        $rules = [
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
                'id_user'       => $user['id_user'],
                'id_kategori'   => $this->request->getVar('id_kategori'),
                'nama_produk'   => $this->request->getVar('nama_produk'),
                'harga'         => $this->request->getVar('harga'),
                'gambar_produk' => $namaGambar,
                'is_active'     => 1 // 👈 Otomatis Aktif saat dibuat
            ];

            $model->insert($data);

            // 📝 CATAT LOG TAMBAH PRODUK
            $logModel = new LogAktivitasModel();
            $logModel->insert([
                'id_user'    => $user['id_user'],
                'aksi'       => 'TAMBAH_PRODUK',
                'keterangan' => 'Menambahkan produk baru: ' . $data['nama_produk'] . ' seharga Rp ' . number_format($data['harga'], 0, ',', '.')
            ]);

            return $this->respondCreated([
                'status'  => 201,
                'message' => 'Produk berhasil ditambahkan.',
                'data'    => $data
            ]);
        }
    }

    // ==========================================
    // 4. UBAH & NONAKTIFKAN PRODUK (UPDATE)
    // ==========================================
    public function update($id = null)
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $model = new ProdukModel();
        $produkLama = $model->find($id);

        if (!$produkLama) {
            return $this->failNotFound('Data produk tidak ditemukan.');
        } 
        
        if ($user['role'] === 'Owner' && $produkLama['id_user'] != $user['id_user']) {
            return $this->failForbidden('Akses ditolak! Ini bukan produk milik Anda.');
        }

        // 🧹 is_active sudah dihapus dari rules
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

            // 📝 LOGIKA PENCATATAN LOG HANYA UNTUK UPDATE BIASA
            $logModel = new \App\Models\LogAktivitasModel();
            $logModel->insert([
                'id_user'    => $user['id_user'],
                'aksi'       => 'UPDATE_PRODUK',
                'keterangan' => 'Mengubah data produk: ' . $produkLama['nama_produk']
            ]);

            return $this->respond([
                'status'  => 200,
                'message' => 'Data produk berhasil diperbarui.',
                'data'    => $data
            ]);
        }
    }
    // ==========================================
    // 5. HAPUS PRODUK (SOFT DELETE)
    // ==========================================
    public function delete($id = null)
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $model = new ProdukModel();
        $produk = $model->find($id);

        if (!$produk) {
            return $this->failNotFound('Data produk tidak ditemukan.');
        } 

        if ($user['role'] === 'Owner' && $produk['id_user'] != $user['id_user']) {
            return $this->failForbidden('Akses ditolak! Anda tidak bisa menghapus produk Owner lain.');
        }

        // 1. CI4 otomatis melakukan Soft Delete (mengisi kolom deleted_at)
        $model->delete($id);

        // 2. Catat di Log Aktivitas
        $logModel = new \App\Models\LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $user['id_user'],
            'aksi'       => 'HAPUS_PRODUK',
            'keterangan' => 'Menghapus permanen (Soft Delete) produk: ' . $produk['nama_produk']
        ]);

        return $this->respondDeleted([
            'status'  => 200,
            'message' => 'Produk berhasil dihapus dari sistem.'
        ]);
    }
    public function updateStatus($id = null)
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Token tidak valid.');

        $model = new ProdukModel();
        $produkLama = $model->find($id);

        if (!$produkLama) {
            return $this->failNotFound('Data produk tidak ditemukan.');
        } 
        
        if ($user['role'] === 'Owner' && $produkLama['id_user'] != $user['id_user']) {
            return $this->failForbidden('Akses ditolak! Ini bukan produk milik Anda.');
        }

        $json = $this->request->getJSON();
        
        if (!isset($json->is_active)) {
            return $this->fail('Parameter is_active (0 atau 1) wajib dikirim dalam bentuk JSON.', 400);
        }

        $statusBaru = $json->is_active;

        if (!in_array($statusBaru, [0, 1])) {
            return $this->failValidationErrors(['is_active' => 'Nilai is_active harus 0 atau 1']);
        }

        // Cek jika statusnya tidak berubah, tidak perlu buang-buang query ke DB
        if ($statusBaru == $produkLama['is_active']) {
            return $this->respond([
                'status'  => 200,
                'message' => 'Status produk tidak ada perubahan.'
            ]);
        }

        // Eksekusi Update Status
        $model->update($id, ['is_active' => $statusBaru]);

        // 📝 LOGIKA PENCATATAN LOG KHUSUS STATUS
        $aksiLog = ($statusBaru == 1) ? 'AKTIFKAN_PRODUK' : 'NONAKTIFKAN_PRODUK';
        $statusText = ($statusBaru == 1) ? 'Mengaktifkan kembali' : 'Menonaktifkan';
        
        $logModel = new \App\Models\LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $user['id_user'],
            'aksi'       => $aksiLog,
            'keterangan' => $statusText . ' produk: ' . $produkLama['nama_produk']
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Status produk berhasil diubah menjadi ' . (($statusBaru == 1) ? 'Aktif' : 'Nonaktif') . '.',
            'data'    => ['is_active' => $statusBaru]
        ]);
    }
}