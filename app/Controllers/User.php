<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\UserModel;
use App\Models\LogAktivitasModel; // 🚨 PENTING: Panggil model Log
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

class User extends ResourceController
{
    protected $format = 'json';

    public function login()
    {
        $model = new UserModel();
        $json  = $this->request->getJSON();

        $username = $json->username ?? null;
        $password = $json->password ?? null;

        if (!$username || !$password) {
            return $this->fail('Username dan Password wajib diisi.', 400);
        } else {
            $user = $model->where('username', $username)->first();
            if (!$user) {
                return $this->failNotFound('Username tidak ditemukan.');
            } else {
                if ($user['is_active'] == 0) {
                    return $this->failUnauthorized('Akun Anda telah dinonaktifkan. Silakan hubungi Admin.');
                } else {
                    if (!password_verify($password, $user['password'])) {
                        return $this->failUnauthorized('Password salah.');
                    } else {
                        $key = getenv('JWT_SECRET');
                        $iat = time();
                        $exp = $iat + (60 * 60 * 24);

                        $payload = [
                            "iss"  => "pos_dlatar",
                            "aud"  => "flutter_app",
                            "iat"  => $iat,
                            "exp"  => $exp,
                            "uid"  => $user['id_user'],
                            "role" => $user['role']
                        ];

                        $token = JWT::encode($payload, $key, 'HS256');

                        // ==========================================
                        // 📝 CATAT LOG LOGIN (BUKA SHIFT)
                        // ==========================================
                        $logModel = new LogAktivitasModel();
                        $logModel->insert([
                            'id_user'    => $user['id_user'],
                            'aksi'       => 'LOGIN',
                            'keterangan' => 'Memulai sesi ' . strtolower($user['role']) . ' (Login)'
                        ]);

                        unset($user['password']);
                        unset($user['pin_hash']);

                        return $this->respond([
                            'status'   => 200,
                            'message'  => 'Login Berhasil',
                            'token'    => $token,
                            'data'     => $user
                        ]);
                    }
                }
            }
        }
    }

    public function logout()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');

        if (!$header) {
            return $this->fail('Anda belum login atau token tidak ditemukan.', 400);
        } else {
            try {
                // Bongkar token untuk tahu siapa yang logout
                $token   = explode(' ', $header)[1];
                $key     = getenv('JWT_SECRET');
                $decoded = JWT::decode($token, new Key($key, 'HS256'));
                
                // ==========================================
                // 📝 CATAT LOG LOGOUT (TUTUP SHIFT)
                // ==========================================
                $logModel = new LogAktivitasModel();
                $logModel->insert([
                    'id_user'    => $decoded->uid,
                    'aksi'       => 'LOGOUT',
                    'keterangan' => 'Mengakhiri sesi (Logout/Tutup Shift)'
                ]);

                return $this->respond([
                    'status'  => 200,
                    'message' => 'Logout berhasil di sisi server. Silakan hapus token di memori lokal perangkat (Flutter).'
                ]);
            } catch (\Exception $e) {
                return $this->failUnauthorized('Token tidak valid untuk proses logout.');
            }
        }
    }

    public function index()
    {
        $model = new UserModel();
        
        $status = $this->request->getGet('status');

        if ($status === 'aktif') {
            $model->where('is_active', 1);
        } else if ($status === 'nonaktif') {
            $model->where('is_active', 0);
        }

        $users = $model->findAll();
        $dataResponse = [];

        if (!empty($users)) {
            foreach ($users as $user) {
                $dataResponse[] = [
                    'id_user'         => $user['id_user'],
                    'username'        => $user['username'],
                    'nama_lengkap'    => $user['nama_lengkap'],
                    'role'            => $user['role'],
                    'is_active'       => (int) $user['is_active'],
                    'no_hp'           => $user['no_hp'],
                    'foto_profile'    => $user['foto_profile'],
                    'has_pin'         => !empty($user['pin_hash']),
                    'has_biometric'   => !empty($user['biometric_token']),
                    'created_at'      => $user['created_at'],
                    'updated_at'      => $user['updated_at'],
                ];
            }
        }

        return $this->respond([
            'status'  => 200,
            'message' => 'Daftar user berhasil dimuat.',
            'filter'  => $status ?? 'semua',
            'data'    => $dataResponse
        ]);
    }

    public function show($id = null)
    {
        $model = new UserModel();
        $user = $model->find($id);

        if (!$user) {
            return $this->failNotFound('Data user tidak ditemukan.');
        } else {
            $dataResponse = [
                'id_user'         => $user['id_user'],
                'username'        => $user['username'],
                'nama_lengkap'    => $user['nama_lengkap'],
                'role'            => $user['role'],
                'is_active'       => (int) $user['is_active'],
                'no_hp'           => $user['no_hp'],
                'foto_profile'    => $user['foto_profile'],
                'has_pin'         => !empty($user['pin_hash']),
                'has_biometric'   => !empty($user['biometric_token']),
                'created_at'      => $user['created_at'],
                'updated_at'      => $user['updated_at'],
            ];

            return $this->respond([
                'status'  => 200,
                'message' => 'Detail user berhasil dimuat.',
                'data'    => $dataResponse
            ]);
        }
    }

    public function profile()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');

        if (!$header) {
            return $this->failUnauthorized('Token tidak ditemukan.');
        } else {
            $token = explode(' ', $header)[1] ?? null;

            try {
                $key = getenv('JWT_SECRET');
                $decoded = JWT::decode($token, new Key($key, 'HS256'));
                $id = $decoded->uid;

                $model = new UserModel();
                $user = $model->find($id);

                if (!$user) {
                    return $this->failNotFound('Profil tidak ditemukan.');
                } else {
                    $dataResponse = [
                        'id_user'         => $user['id_user'] ?? $id,
                        'username'        => $user['username'] ?? '',
                        'nama_lengkap'    => $user['nama_lengkap'] ?? '',
                        'role'            => $user['role'] ?? '',
                        'no_hp'           => $user['no_hp'] ?? null,
                        'foto_profile'    => $user['foto_profile'] ?? null,
                        'has_pin'         => !empty($user['pin_hash']),
                        'has_biometric'   => !empty($user['biometric_token']),
                        'created_at'      => $user['created_at'] ?? null,
                        'updated_at'      => $user['updated_at'] ?? null,
                    ];

                    return $this->respond([
                        'status'  => 200,
                        'message' => 'Data profil berhasil dimuat.',
                        'data'    => $dataResponse
                    ]);
                }
            } catch (\Exception $e) {
                return $this->failUnauthorized('Token tidak valid atau sudah kadaluarsa.');
            }
        }
    }

    public function create()
    {
        $model = new UserModel();

        $rules = [
            'username'     => 'required|alpha_numeric|is_unique[users.username]',
            'password'     => 'required|min_length[6]',
            'nama_lengkap' => 'required|string',
            'role'         => 'required|in_list[Admin,Kasir,Owner]'
        ];

        $json = $this->request->getJSON();

        if (!$json) {
            return $this->fail('Body JSON kosong.', 400);
        } else {
            $data = [
                'username'     => $json->username ?? null,
                'password'     => $json->password ?? null,
                'nama_lengkap' => $json->nama_lengkap ?? null,
                'role'         => $json->role ?? null
            ];

            if (!$this->validateData($data, $rules)) {
                return $this->failValidationErrors($this->validator->getErrors());
            } else {
                $model->insert($data);
                unset($data['password']);

                return $this->respondCreated([
                    'status'   => 201,
                    'messages' => ['success' => 'User berhasil didaftarkan.'],
                    'data'     => $data
                ]);
            }
        }
    }

    public function update($id = null)
    {
        $model = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('User tidak ditemukan.');
        } else {
            $rules = [
                'is_active' => 'permit_empty|in_list[0,1]'
            ];
            if (!$this->validate($rules)) {
                return $this->failValidationErrors($this->validator->getErrors());
            } else {
                $data = [
                    'is_active' => $this->request->getVar('is_active') ?? $userLama['is_active'],
                ];

                $model->update($id, $data);

                return $this->respond([
                    'status'   => 200,
                    'messages' => ['success' => 'Status manajemen user berhasil diperbarui.'],
                    'data'     => $data
                ]);
            }
        }
    }

    public function resetPassword($id = null)
    {
        // 1. Ambil ID pengakses (Admin) dari JWT
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');
        
        try {
            $token   = explode(' ', $header)[1];
            $key     = getenv('JWT_SECRET');
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            $id_pengakses = $decoded->uid;
        } catch (\Exception $e) {
            return $this->failUnauthorized('Token tidak valid.');
        }

        $model = new UserModel();
        $user = $model->find($id);

        if (!$user) {
            return $this->failNotFound('User tidak ditemukan.');
        } else {
            $defaultPassword = '123456';
            $data = ['password' => $defaultPassword];
            $model->update($id, $data);

            // ==========================================
            // 📝 CATAT LOG RESET PASSWORD
            // ==========================================
            $logModel = new LogAktivitasModel();
            $logModel->insert([
                'id_user'    => $id_pengakses, // Yang dicatat adalah yang mengeksekusi (Admin)
                'aksi'       => 'RESET_PASSWORD',
                'keterangan' => 'Mereset password milik akun: ' . $user['nama_lengkap']
            ]);

            return $this->respond([
                'status'   => 200,
                'messages' => [
                    'success' => 'Password berhasil direset.',
                    'info'    => 'Password default saat ini adalah: 123456'
                ]
            ]);
        }
    }

    public function verifyPin($id = null)
    {
        $json = $this->request->getJSON();
        $pinInput = $json->pin ?? null;

        if (!$pinInput) {
            return $this->fail('PIN wajib diisi.', 400);
        } else {
            $model = new UserModel();
            $user = $model->find($id);

            if (!$user) {
                return $this->failNotFound('User tidak ditemukan.');
            } else {
                if (empty($user['pin_hash'])) {
                    return $this->fail('User ini belum mengatur PIN keamanan.', 400);
                } else {
                    if (!password_verify((string)$pinInput, (string)$user['pin_hash'])) {
                        return $this->failUnauthorized('PIN yang Anda masukkan salah!');
                    } else {
                        return $this->respond([
                            'status'  => 200,
                            'message' => 'PIN valid. Otorisasi diberikan.'
                        ]);
                    }
                }
            }
        }
    }

    public function updateBiodata()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        $token  = explode(' ', $header)[1];
        $key    = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $id      = $decoded->uid;

        $model    = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('Data user tidak ditemukan.');
        } else {
            $json = $this->request->getJSON();

            if (!$json) {
                return $this->fail('Format data tidak valid.', 400);
            } else {
                $rules = [
                    'username'     => "permit_empty|alpha_numeric|is_unique[users.username,id_user,{$id}]",
                    'nama_lengkap' => 'permit_empty|string',
                    'no_hp'        => 'permit_empty|numeric|min_length[10]|max_length[15]'
                ];

                $data = [
                    'username'     => (!empty($json->username)) ? $json->username : $userLama['username'],
                    'nama_lengkap' => (!empty($json->nama_lengkap)) ? $json->nama_lengkap : $userLama['nama_lengkap'],
                    'no_hp'        => (!empty($json->no_hp)) ? $json->no_hp : $userLama['no_hp'],
                ];

                if (!$this->validateData($data, $rules)) {
                    return $this->failValidationErrors($this->validator->getErrors());
                } else {
                    $model->update($id, $data);

                    return $this->respond([
                        'status'   => 200,
                        'messages' => ['success' => 'Biodata profil berhasil diperbarui.'],
                        'data'     => $data
                    ]);
                }
            }
        }
    }

    public function updatePhoto()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        $token  = explode(' ', $header)[1];
        $key    = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $id      = $decoded->uid;

        $model    = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('Data user tidak ditemukan.');
        } else {
            $fileFoto = $this->request->getFile('foto_profile');

            if (!$fileFoto || !$fileFoto->isValid()) {
                return $this->fail('Tidak ada file foto yang diunggah atau file rusak.', 400);
            } else {
                $rules = [
                    'foto_profile' => 'max_size[foto_profile,2048]|is_image[foto_profile]|mime_in[foto_profile,image/jpg,image/jpeg,image/png]'
                ];

                if (!$this->validate($rules)) {
                    return $this->failValidationErrors($this->validator->getErrors());
                } else {
                    if (!empty($userLama['foto_profile'])) {
                        $pathLama = 'uploads/profile/' . $userLama['foto_profile'];
                        if (file_exists($pathLama)) {
                            unlink($pathLama); 
                        }
                    }

                    $namaFotoBaru = $fileFoto->getRandomName();
                    $fileFoto->move('uploads/profile', $namaFotoBaru);

                    $model->update($id, ['foto_profile' => $namaFotoBaru]);

                    return $this->respond([
                        'status'   => 200,
                        'messages' => ['success' => 'Foto profil berhasil diperbarui.'],
                        'data'     => [
                            'foto_profile' => $namaFotoBaru
                        ]
                    ]);
                }
            }
        }
    }

    public function updateSecurity()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        $token  = explode(' ', $header)[1];
        $key    = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        $id      = $decoded->uid;

        $model    = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('Data user tidak ditemukan.');
        } else {
            $json = $this->request->getJSON();
            if (!$json) return $this->fail('Format data tidak valid.', 400);

            if (empty($json->password_lama)) {
                return $this->fail('Password lama wajib diisi untuk verifikasi keamanan.', 400);
            }
            if (!password_verify($json->password_lama, (string)$userLama['password'])) {
                return $this->failUnauthorized('Password lama yang Anda masukkan salah!');
            }

            $data = [];
            $messages = [];

            if (!empty($json->password_baru)) {
                if (strlen($json->password_baru) < 6) {
                    return $this->failValidationErrors(['password_baru' => 'Password baru minimal 6 karakter.']);
                }
                $data['password'] = $json->password_baru; 
                $messages[] = "Password";
            }

            if (!empty($json->pin_baru)) {
                if (!is_numeric($json->pin_baru) || strlen($json->pin_baru) != 6) {
                    return $this->failValidationErrors(['pin_baru' => 'PIN harus berupa 6 digit angka.']);
                }
                $data['pin_hash'] = $json->pin_baru; 
                $messages[] = "PIN keamanan";
            }

            if (empty($data)) {
                return $this->fail('Tidak ada data keamanan yang diubah.', 400);
            }

            $model->update($id, $data);

            // ==========================================
            // 📝 CATAT LOG UPDATE SECURITY
            // ==========================================
            $logModel = new LogAktivitasModel();
            $logModel->insert([
                'id_user'    => $id,
                'aksi'       => 'UPDATE_SECURITY',
                'keterangan' => 'Memperbarui data keamanan: ' . implode(" dan ", $messages)
            ]);

            return $this->respond([
                'status'   => 200,
                'messages' => ['success' => implode(" dan ", $messages) . " berhasil diperbarui."],
            ]);
        }
    }

    public function getListKasir()
    {
        $model = new UserModel();
        $kasir = $model->select('id_user, nama_lengkap, persentase_komisi, foto_profile')
            ->where('role', 'Kasir')
            ->findAll();

        return $this->respond([
            'status' => 200,
            'data'   => $kasir
        ]);
    }

    public function updateKomisi($id = null)
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');
        
        $token   = explode(' ', $header)[1];
        $key     = getenv('JWT_SECRET');
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        
        $userModel = new \App\Models\UserModel();
        $pengakses = $userModel->find($decoded->uid);

        if (!$pengakses || ($pengakses['role'] !== 'Admin' && $pengakses['role'] !== 'Owner')) {
            return $this->failForbidden('Akses ditolak! Anda tidak memiliki izin untuk mengubah komisi.');
        }

        $kasirLama = $userModel->find($id);
        if (!$kasirLama || $kasirLama['role'] !== 'Kasir') {
            return $this->failNotFound('Data kasir tidak ditemukan.');
        }

        $rules = [
            'persentase_komisi' => 'required|numeric|greater_than_equal_to[0]|less_than_equal_to[100]'
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $json = $this->request->getJSON();
        $dataUpdate = [
            'persentase_komisi' => $json->persentase_komisi
        ];

        $userModel->update($id, $dataUpdate);

        $logModel = new LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $pengakses['id_user'],
            'aksi'       => 'UPDATE_KOMISI',
            'keterangan' => 'Mengubah persentase komisi kasir ' . $kasirLama['nama_lengkap'] . ' menjadi ' . $json->persentase_komisi . '%'
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Persentase komisi kasir ' . $kasirLama['nama_lengkap'] . ' berhasil diperbarui.',
            'data'    => $dataUpdate
        ]);
    }
    // ==========================================
    // Mendaftarkan Token Biometrik (Wajib Login)
    // ==========================================
    public function registerBiometric()
    {
        $header = $this->request->getServer('HTTP_AUTHORIZATION');
        if (!$header) return $this->failUnauthorized('Token tidak ditemukan.');

        try {
            // 1. Bongkar JWT untuk tahu siapa yang sedang login
            $token   = explode(' ', $header)[1];
            $key     = getenv('JWT_SECRET');
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($key, 'HS256'));
            $idUser  = $decoded->uid;

            // 2. Tangkap token biometrik dari Flutter
            $json = $this->request->getJSON();
            if (!isset($json->biometric_token) || empty($json->biometric_token)) {
                return $this->fail('Biometric token wajib dikirim.', 400);
            }

            // 3. Simpan ke tabel users
            $userModel = new \App\Models\UserModel();
            $update = $userModel->update($idUser, ['biometric_token' => $json->biometric_token]);

            if ($update) {
                // 📝 CATAT LOG AKTIVITAS
                $logModel = new \App\Models\LogAktivitasModel();
                $logModel->insert([
                    'id_user'    => $idUser,
                    'aksi'       => 'REGISTER_BIOMETRIC',
                    'keterangan' => 'Mendaftarkan perangkat untuk login biometrik.'
                ]);

                return $this->respond([
                    'status'  => 200,
                    'message' => 'Token biometrik berhasil didaftarkan.'
                ]);
            } else {
                return $this->fail('Gagal mendaftarkan token biometrik.', 500);
            }

        } catch (\Exception $e) {
            return $this->failUnauthorized('Token JWT tidak valid atau kadaluarsa.');
        }
    }
    // ==========================================
    // Login Menggunakan Biometrik (Bebas Akses)
    // ==========================================
    public function loginBiometric()
    {
        $json = $this->request->getJSON();
        
        if (!isset($json->biometric_token) || empty($json->biometric_token)) {
            return $this->fail('Biometric token wajib dikirim.', 400);
        }

        $userModel = new \App\Models\UserModel();
        
        // Cari user yang memiliki token biometrik ini
        $user = $userModel->where('biometric_token', $json->biometric_token)->first();

        // Jika tidak ketemu (token salah / belum daftar)
        if (!$user) {
            return $this->failUnauthorized('Login biometrik gagal. Perangkat tidak dikenali.');
        }

        // Jika ketemu, buatkan Token JWT baru
        $key = getenv('JWT_SECRET');
        $payload = [
            "iat"  => time(),
            "exp"  => time() + (60 * 60 * 24), // Token berlaku 24 jam
            "uid"  => $user['id_user'],
            "role" => $user['role']
        ];

        $token = \Firebase\JWT\JWT::encode($payload, $key, 'HS256');

        // 📝 CATAT LOG AKTIVITAS LOGIN
        $logModel = new \App\Models\LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $user['id_user'],
            'aksi'       => 'LOGIN_BIOMETRIC',
            'keterangan' => 'Memulai sesi menggunakan login biometrik.'
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Login biometrik berhasil.',
            'token'   => $token,
            'user'    => [
                'id_user'      => $user['id_user'],
                'nama_lengkap' => $user['nama_lengkap'], // Sesuaikan dengan nama kolom di DB-mu
                'role'         => $user['role']
            ]
        ]);
    }
    public function requestOtp()
    {
        $json = $this->request->getJSON();
        if (!isset($json->no_hp)) return $this->fail('Nomor WA wajib diisi.', 400);

        $userModel = new \App\Models\UserModel();
        $user = $userModel->where('no_hp', $json->no_hp)->first();

        if (!$user) return $this->failNotFound('Nomor WA tidak terdaftar.');

        // 1. Buat 6 Digit OTP & Expired (15 Menit)
        $otp = rand(100000, 999999);
        $expiredAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // 2. Simpan ke Database
        $userModel->update($user['id_user'], [
            'otp_code' => $otp,
            'otp_expired_at' => $expiredAt
        ]);

        // 3. Persiapkan API Fonnte
        $tokenFonnte = getenv('FONNTE_TOKEN'); // 👈 Membaca dari file .env
        $pesanWa = "Halo *{$user['nama_lengkap']}*, kode OTP reset password D'Latar Anda adalah: *{$otp}*.\n\nKode ini hangus dalam 15 menit. Jangan berikan ke siapapun demi keamanan akun Anda!";
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.fonnte.com/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array(
                'target' => $json->no_hp,
                'message' => $pesanWa, 
                'countryCode' => '62',
            ),
            CURLOPT_HTTPHEADER => array(
                'Authorization: ' . $tokenFonnte
            ),
        ));

        // 4. Tembak Fonnte!
        $responseFonnte = curl_exec($curl);
        curl_close($curl);
        
        return $this->respond([
            'status'  => 200,
            'message' => 'Kode OTP berhasil dikirim ke WhatsApp Anda.'
        ]);
    }
    public function resetPasswordOtp()
    {
        $json = $this->request->getJSON();
        
        if (!isset($json->no_hp) || !isset($json->otp) || !isset($json->password_baru)) {
            return $this->fail('Nomor WA, OTP, dan Password Baru wajib diisi.', 400);
        }

        $userModel = new \App\Models\UserModel();
        $user = $userModel->where('no_hp', $json->no_hp)->first();

        if (!$user) return $this->failNotFound('Nomor WA tidak terdaftar.');

        // 1. Cek Kecocokan OTP
        if ($user['otp_code'] != $json->otp) {
            return $this->fail('Kode OTP salah.', 400);
        }

        // 2. Cek Apakah OTP Sudah Kadaluarsa
        $sekarang = date('Y-m-d H:i:s');
        if ($sekarang > $user['otp_expired_at']) {
            return $this->fail('Kode OTP sudah kadaluarsa. Silakan minta kode baru.', 400);
        }

        // 3. OTP Valid! Hash Password Baru dan Kosongkan OTP
        $passwordHash = password_hash($json->password_baru, PASSWORD_DEFAULT);

        $userModel->update($user['id_user'], [
            'password'       => $json->password_baru, // 👈 Cukup begini saja!
            'otp_code'       => null,       
            'otp_expired_at' => null
        ]);

        // 📝 Opsional: Catat log aktivitas jika mau
        $logModel = new \App\Models\LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $user['id_user'],
            'aksi'       => 'RESET_PASSWORD',
            'keterangan' => 'Berhasil mereset password menggunakan OTP WhatsApp.'
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Password berhasil diubah. Silakan login dengan password baru.'
        ]);
    }
}