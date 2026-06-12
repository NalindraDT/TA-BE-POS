<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key; // Wajib dipanggil untuk JWT versi terbaru
use Config\Services;

class JwtFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // 1. Tangkap Header Authorization dari Flutter/Postman
        $header = $request->getServer('HTTP_AUTHORIZATION');

        if (!$header) {
            return Services::response()
                ->setJSON(['status' => 401, 'message' => 'Akses ditolak! Token tidak ditemukan.'])
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED);
        } else {
            // 2. Pecah string "Bearer eyJhbGci..." untuk mengambil tokennya saja
            $token = explode(' ', $header)[1] ?? null;

            if (!$token) {
                return Services::response()
                    ->setJSON(['status' => 401, 'message' => 'Format token salah! Gunakan format Bearer.'])
                    ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED);
            } else {
                // 3. Verifikasi keaslian Token
                try {
                    $key = getenv('JWT_SECRET');
                    
                    // Proses Decode (Membuka segel). Jika token diubah 1 huruf saja oleh hacker, ini akan error.
                    $decoded = JWT::decode($token, new Key($key, 'HS256'));
                    
                    // JIKA SUKSES: Biarkan proses berlanjut ke Controller yang dituju.
                    
                } catch (\Exception $e) {
                    // JIKA GAGAL (Token expired atau palsu)
                    return Services::response()
                        ->setJSON(['status' => 401, 'message' => 'Token kadaluarsa atau tidak valid! Silakan login ulang.'])
                        ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED);
                }
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Bagian ini dibiarkan kosong karena kita hanya mencegat di awal (before)
    }
}