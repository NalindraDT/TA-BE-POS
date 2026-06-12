<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\LogAktivitasModel;
use App\Models\UserModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class LogAktivitas extends ResourceController
{
    protected $format = 'json';

    public function index()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');
        
        $token   = explode(' ', $header)[1];
        $key     = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $idPengakses = $decoded->uid;

        $userModel = new UserModel();
        $pengakses = $userModel->find($idPengakses);

        if (!$pengakses) {
            return $this->failNotFound('Data user tidak ditemukan.');
        }

        // 1. Tangkap parameter dari Postman/Flutter
        $idTarget = $this->request->getGet('id_user');
        $page     = $this->request->getGet('page') ?? 1; // 👈 Tangkap request halaman
        $perPage  = 10;                                  // 👈 Batas 10 data

        $logModel = new LogAktivitasModel();
        
        // Catatan: $builder di sini mewakili instance $logModel
        $builder  = $logModel->select('log_aktivitas.*, users.nama_lengkap, users.role')
                             ->join('users', 'users.id_user = log_aktivitas.id_user', 'left')
                             ->orderBy('log_aktivitas.created_at', 'DESC');

        // 2. Logika Hak Akses & Terapkan Filter
        if ($pengakses['role'] === 'Admin') {
            
            if (!empty($idTarget)) {
                $builder->where('log_aktivitas.id_user', $idTarget);
            }
            
        } else if ($pengakses['role'] === 'Owner') {
            
            $builder->groupStart()
                        ->where('log_aktivitas.id_user', $idPengakses)
                        ->orWhere('users.role', 'Kasir')
                    ->groupEnd();

            if (!empty($idTarget)) {
                $builder->where('log_aktivitas.id_user', $idTarget);
            }
            
        } else if ($pengakses['role'] === 'Kasir') {
            
            // 🛡️ BENTENG KASIR: Hanya melihat log dirinya sendiri
            $builder->where('log_aktivitas.id_user', $idPengakses);

        } else {
            return $this->failForbidden('Akses ditolak! Anda tidak memiliki izin untuk melihat riwayat aktivitas.');
        }

        // 3. Eksekusi Pagination (Menggantikan semua findAll) 🚀
        $data = $builder->paginate($perPage, 'log', $page);
        $pager = $logModel->pager;

        if (empty($data)) {
            return $this->respond([
                'status'  => 200,
                'message' => 'Belum ada riwayat aktivitas.',
                'data'    => []
            ]);
        }

        return $this->respond([
            'status'  => 200,
            'message' => 'Log aktivitas berhasil dimuat',
            'filter'  => [
                'role_pengakses' => $pengakses['role'],
                'id_user_dicari' => $idTarget ?? 'Semua User (Sesuai Hak Akses)'
            ],
            // 4. Tambahkan info metadata paginasi untuk frontend
            'pagination' => [
                'halaman_sekarang' => (int) $page,
                'total_halaman'    => $pager->getPageCount('log'),
                'total_data'       => $pager->getTotal('log'),
                'data_per_halaman' => $perPage
            ], 
            'data'    => $data
        ]);
    }
}