<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\UserModel;
use App\Models\LogAktivitasModel;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

class User extends ResourceController
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

    public function login()
    {
        $model = new UserModel();

        $username = $this->request->getVar('username');
        $password = $this->request->getVar('password');

        if (!$username || !$password) {
            return $this->fail('Username dan Password wajib diisi.', 400);
        }

        $user = $model->where('username', $username)->first();

        if (!$user) {
            return $this->failNotFound('Username tidak ditemukan.');
        }

        if ($user['is_active'] == 0) {
            return $this->failUnauthorized('Akun Anda telah dinonaktifkan. Silakan hubungi Admin.');
        }

        if (!password_verify($password, $user['password'])) {
            return $this->failUnauthorized('Password salah.');
        }

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

        // 📝 CATAT LOG LOGIN (BUKA SHIFT)
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

    public function logout()
    {
        $user = $this->getLoggedInUser();

        if (!$user) {
            return $this->failUnauthorized('Anda belum login atau token tidak ditemukan.');
        }

        // 📝 CATAT LOG LOGOUT (TUTUP SHIFT)
        $logModel = new LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $user['id_user'],
            'aksi'       => 'LOGOUT',
            'keterangan' => 'Mengakhiri sesi (Logout/Tutup Shift)'
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Logout berhasil di sisi server. Silakan hapus token di memori lokal perangkat (Flutter).'
        ]);
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
        }

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

    public function profile()
    {
        $user = $this->getLoggedInUser();
        if (!$user) return $this->failUnauthorized('Profil tidak ditemukan atau token tidak valid.');

        $dataResponse = [
            'id_user'         => $user['id_user'],
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

    public function create()
    {
        $userLogin = $this->getLoggedInUser();
        if (!$userLogin || $userLogin['role'] !== 'Admin') {
            return $this->failForbidden('Hanya Admin yang dapat menambah user.');
        }

        $model = new UserModel();

        $rules = [
            'username'     => 'required|alpha_numeric|is_unique[users.username]',
            'nama_lengkap' => 'required|string|is_unique[users.nama_lengkap]',
            'password'     => 'required|min_length[6]',
            'role'         => 'required|in_list[Admin,Kasir,Owner]'
        ];

        $messages = [
            'username' => [
                'required'      => 'Username wajib diisi.',
                'alpha_numeric' => 'Username tidak boleh mengandung spasi atau karakter khusus.',
                'is_unique'     => 'Username ini sudah dipakai pengguna lain. Silakan cari username baru.'
            ],
            'nama_lengkap' => [
                'required'  => 'Nama lengkap wajib diisi.',
                'is_unique' => 'Nama lengkap ini sudah terdaftar. Gunakan nama lain atau tambahkan nama belakang.'
            ]
        ];

        $data = [
            'username'     => $this->request->getVar('username'),
            'password'     => $this->request->getVar('password'),
            'nama_lengkap' => $this->request->getVar('nama_lengkap'),
            'role'         => $this->request->getVar('role'),
            'is_active'    => 1
        ];

        // Validasi wajib diisi untuk GetVar()
        if (empty($data['username']) || empty($data['password']) || empty($data['nama_lengkap']) || empty($data['role'])) {
            return $this->fail('Mohon lengkapi semua form yang wajib diisi', 400);
        }

        if (!$this->validate($rules, $messages)) {
            $errors = $this->validator->getErrors();
            return $this->respond([
                'status'  => 400,
                'message' => reset($errors)
            ], 400);
        }

        $model->insert($data);
        unset($data['password']);

        return $this->respondCreated([
            'status'   => 201,
            'messages' => ['success' => 'User berhasil didaftarkan.'],
            'data'     => $data
        ]);
    }

    public function update($id = null)
    {
        $userLogin = $this->getLoggedInUser();

        if (!$userLogin || ($userLogin['role'] !== 'Admin' && $userLogin['role'] !== 'Owner')) {
            return $this->failForbidden('Akses ditolak! Hanya Admin/Owner yang dapat mengedit data user.');
        }

        $model = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('Data user tidak ditemukan.');
        }

        $rules = [
            'username'          => "permit_empty|alpha_numeric|is_unique[users.username,id_user,{$id}]",
            'nama_lengkap'      => "permit_empty|string|is_unique[users.nama_lengkap,id_user,{$id}]",
            'is_active'         => 'permit_empty|in_list[0,1]',
            'no_hp'             => "permit_empty|numeric|min_length[10]|max_length[15]|is_unique[users.no_hp,id_user,{$id}]",
            'persentase_komisi' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[100]'
        ];

        $messages = [
            'username' => [
                'is_unique' => 'Gagal mengubah: Username ini sudah dipakai oleh pengguna lain.'
            ],
            'nama_lengkap' => [
                'is_unique' => 'Gagal mengubah: Nama lengkap ini sudah terdaftar pada akun lain.'
            ],
            'no_hp' => [
                'is_unique'  => 'Gagal mengubah: Nomor HP ini sudah terdaftar di sistem.',
                'min_length' => 'Nomor HP tidak valid. Minimal harus 10 digit angka.'
            ]
        ];

        if (!$this->validate($rules, $messages)) {
            $errors = $this->validator->getErrors();
            return $this->respond([
                'status'  => 400,
                'message' => reset($errors)
            ], 400);
        }

        $dataUpdate = [
            'username'          => $this->request->getVar('username') ?? $userLama['username'],
            'nama_lengkap'      => $this->request->getVar('nama_lengkap') ?? $userLama['nama_lengkap'],
            'is_active'         => $this->request->getVar('is_active') ?? $userLama['is_active'],
            'no_hp'             => $this->request->getVar('no_hp') ?? $userLama['no_hp'],
            'persentase_komisi' => $this->request->getVar('persentase_komisi') ?? $userLama['persentase_komisi'],
        ];
        if ($userLama['role'] === 'Kasir' && $dataUpdate['is_active'] == 0) {
            $dataUpdate['persentase_komisi'] = 0;
        }

        $model->update($id, $dataUpdate);

        // 📝 CATAT LOG UPDATE USER OLEH ADMIN / OWNER
        $logModel = new LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $userLogin['id_user'],
            'aksi'       => 'UPDATE_USER',
            'keterangan' => $userLogin['role'] . ' mengubah data akun milik: ' . $userLama['nama_lengkap']
        ]);

        return $this->respond([
            'status'   => 200,
            'message'  => 'Data user berhasil diperbarui.',
            'data'     => $dataUpdate
        ]);
    }
    public function delete($id = null)
    {
        $userLogin = $this->getLoggedInUser();

        // Hanya Admin dan Owner yang boleh menghapus
        if (!$userLogin || ($userLogin['role'] !== 'Admin' && $userLogin['role'] !== 'Owner')) {
            return $this->failForbidden('Akses ditolak! Anda tidak memiliki izin untuk menghapus user.');
        }

        $model = new UserModel();
        $userTarget = $model->find($id);

        if (!$userTarget) {
            return $this->failNotFound('Data user tidak ditemukan.');
        }

        // Validasi: Mencegah user menghapus dirinya sendiri saat sedang login
        if ($userLogin['id_user'] == $id) {
            return $this->fail('Gagal: Anda tidak dapat menghapus akun Anda sendiri saat sedang login.', 400);
        }

        if ($userTarget['role'] === 'Kasir') {
            $model->update($id, ['persentase_komisi' => 0]);
        }
        $model->delete($id);

        // 📝 CATAT LOG HAPUS USER
        $logModel = new LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $userLogin['id_user'],
            'aksi'       => 'DELETE_USER',
            'keterangan' => 'Menghapus akun (Soft Delete): ' . $userTarget['nama_lengkap']
        ]);

        return $this->respondDeleted([
            'status'  => 200,
            'message' => 'User berhasil dihapus.'
        ]);
    }

    public function resetPassword($id = null)
    {
        $userLogin = $this->getLoggedInUser();
        if (!$userLogin || $userLogin['role'] !== 'Admin') {
            return $this->failForbidden('Hanya Admin yang diizinkan untuk mereset password.');
        }

        // 1. Ambil input PIN dari Flutter
        $json = $this->request->getJSON();
        $pinInput = $json ? $json->pin : $this->request->getVar('pin');

        if (!$pinInput) {
            return $this->fail('PIN keamanan wajib diisi untuk otorisasi.', 400);
        }

        $model = new UserModel();

        // 2. Cek apakah Admin yang login punya PIN dan PIN-nya cocok
        $adminData = $model->find($userLogin['id_user']);
        if (empty($adminData['pin_hash'])) {
            return $this->fail('Anda belum mengatur PIN keamanan Admin.', 400);
        }

        if (!password_verify((string)$pinInput, (string)$adminData['pin_hash'])) {
            return $this->failUnauthorized('PIN Admin yang Anda masukkan salah!');
        }

        // 3. Cari user target yang akan direset passwordnya
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('User target tidak ditemukan.');
        }

        // 4. Eksekusi reset password
        $model->update($id, ['password' => '123456']);

        // 5. Catat log aktivitas
        $logModel = new LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $userLogin['id_user'],
            'aksi'       => 'RESET_PASSWORD',
            'keterangan' => 'Mereset password milik akun: ' . $userLama['nama_lengkap']
        ]);

        // 6. Kembalikan respon sukses (Pastikan format JSON sesuai dengan yang ditangkap Flutter)
        return $this->respond([
            'status'   => 200,
            'message'  => 'Password berhasil direset.',
            'info'     => 'Password default saat ini adalah: 123456'
        ]);
    }

    public function verifyPin($id = null)
    {
        $pinInput = $this->request->getVar('pin');

        if (!$pinInput) {
            return $this->fail('PIN wajib diisi.', 400);
        }

        $model = new UserModel();
        $user = $model->find($id);

        if (!$user) {
            return $this->failNotFound('User tidak ditemukan.');
        }

        if (empty($user['pin_hash'])) {
            return $this->fail('User ini belum mengatur PIN keamanan.', 400);
        }

        if (!password_verify((string)$pinInput, (string)$user['pin_hash'])) {
            return $this->failUnauthorized('PIN yang Anda masukkan salah!');
        }

        return $this->respond([
            'status'  => 200,
            'message' => 'PIN valid. Otorisasi diberikan.'
        ]);
    }

    public function updateBiodata()
    {
        $userLogin = $this->getLoggedInUser();
        if (!$userLogin) return $this->failUnauthorized('Token tidak valid.');

        $id = $userLogin['id_user'];
        $model = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('Data user tidak ditemukan.');
        }

        $rules = [
            'username'     => "permit_empty|alpha_numeric|is_unique[users.username,id_user,{$id}]",
            'nama_lengkap' => "permit_empty|string|is_unique[users.nama_lengkap,id_user,{$id}]",
            'no_hp'        => "permit_empty|numeric|min_length[10]|max_length[15]|is_unique[users.no_hp,id_user,{$id}]"
        ];

        $messages = [
            'username' => [
                'is_unique' => 'Gagal mengubah: Username ini sudah dipakai.'
            ],
            'nama_lengkap' => [
                'is_unique' => 'Gagal mengubah: Nama lengkap ini sudah terdaftar.'
            ],
            'no_hp' => [
                'is_unique'  => 'Gagal mengubah: Nomor HP ini sudah terdaftar di sistem.',
                'min_length' => 'Nomor HP tidak valid. Minimal 10 digit.'
            ]
        ];

        if (!$this->validate($rules, $messages)) {
            $errors = $this->validator->getErrors();
            return $this->respond([
                'status'  => 400,
                'message' => reset($errors)
            ], 400);
        }

        $dataUpdate = [
            'username'     => $this->request->getVar('username') ?? $userLama['username'],
            'nama_lengkap' => $this->request->getVar('nama_lengkap') ?? $userLama['nama_lengkap'],
            'no_hp'        => $this->request->getVar('no_hp') ?? $userLama['no_hp'],
        ];

        $model->update($id, $dataUpdate);

        return $this->respond([
            'status'   => 200,
            'message'  => 'Biodata profil berhasil diperbarui.',
            'data'     => $dataUpdate
        ]);
    }

    public function updatePhoto()
    {
        $userLogin = $this->getLoggedInUser();
        if (!$userLogin) return $this->failUnauthorized('Token tidak valid.');

        $id = $userLogin['id_user'];
        $model = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('Data user tidak ditemukan.');
        }

        $fileFoto = $this->request->getFile('foto_profile');

        if (!$fileFoto || !$fileFoto->isValid()) {
            return $this->fail('Tidak ada file foto yang diunggah atau file rusak.', 400);
        }

        $rules = [
            'foto_profile' => 'max_size[foto_profile,2048]|is_image[foto_profile]|mime_in[foto_profile,image/jpg,image/jpeg,image/png]'
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

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
            'message'  => 'Foto profil berhasil diperbarui.',
            'data'     => ['foto_profile' => $namaFotoBaru]
        ]);
    }

    public function updateSecurity()
    {
        $userLogin = $this->getLoggedInUser();
        if (!$userLogin) return $this->failUnauthorized('Token tidak valid.');

        $id = $userLogin['id_user'];
        $model = new UserModel();
        $userLama = $model->find($id);

        if (!$userLama) {
            return $this->failNotFound('Data user tidak ditemukan.');
        }

        $password_lama = $this->request->getVar('password_lama');
        $password_baru = $this->request->getVar('password_baru');
        $pin_baru      = $this->request->getVar('pin_baru');

        if (empty($password_lama)) {
            return $this->fail('Password lama wajib diisi untuk verifikasi keamanan.', 400);
        }

        if (!password_verify($password_lama, (string)$userLama['password'])) {
            return $this->failUnauthorized('Password lama yang Anda masukkan salah!');
        }

        $dataUpdate = [];
        $messages = [];

        if (!empty($password_baru)) {
            if (strlen($password_baru) < 6) {
                return $this->fail('Password baru minimal 6 karakter.', 400);
            }
            $dataUpdate['password'] = $password_baru; // Langsung masukkan teks murni
            $messages[] = "Password";
        }

        if (!empty($pin_baru)) {
            if (!is_numeric($pin_baru) || strlen($pin_baru) != 6) {
                return $this->fail('PIN harus berupa tepat 6 digit angka.', 400);
            }
            $dataUpdate['pin_hash'] = $pin_baru; // Langsung masukkan teks murni
            $messages[] = "PIN keamanan";
        }

        if (empty($dataUpdate)) {
            return $this->fail('Tidak ada data keamanan yang diubah.', 400);
        }

        $model->update($id, $dataUpdate);

        // 📝 CATAT LOG UPDATE SECURITY
        $logModel = new LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $id,
            'aksi'       => 'UPDATE_SECURITY',
            'keterangan' => 'Memperbarui data keamanan: ' . implode(" dan ", $messages)
        ]);

        return $this->respond([
            'status'   => 200,
            'message'  => implode(" dan ", $messages) . " berhasil diperbarui."
        ]);
    }

    public function getListKasir()
    {
        $model = new UserModel();
        $kasir = $model->select('id_user, nama_lengkap, persentase_komisi, foto_profile')
            ->where('role', 'Kasir')
            ->where('is_active', 1) // ✨ TAMBAHAN: Hanya ambil kasir yang aktif
            ->findAll();

        return $this->respond([
            'status' => 200,
            'data'   => $kasir
        ]);
    }

    public function updateKomisi($id = null)
    {
        $userLogin = $this->getLoggedInUser();

        // 1. Otorisasi
        if (!$userLogin || ($userLogin['role'] !== 'Admin' && $userLogin['role'] !== 'Owner')) {
            return $this->failForbidden('Akses ditolak! Anda tidak memiliki izin untuk mengubah komisi.');
        }

        $userModel = new UserModel();
        $kasirLama = $userModel->find($id);

        // strcasecmp untuk mengecek role 'Kasir' / 'kasir' tanpa mempedulikan huruf besar/kecil
        if (!$kasirLama || strcasecmp($kasirLama['role'], 'Kasir') !== 0) {
            return $this->failNotFound('Data kasir tidak ditemukan.');
        }

        // 2. Tangkap Input JSON
        $json = $this->request->getJSON();
        $persentaseBaru = $json ? $json->persentase_komisi : $this->request->getVar('persentase_komisi');

        if (!isset($persentaseBaru)) {
            return $this->fail('Parameter persentase_komisi wajib diisi.', 400);
        }

        if (!is_numeric($persentaseBaru) || $persentaseBaru < 0 || $persentaseBaru > 100) {
            return $this->fail('Persentase komisi harus berupa angka antara 0 hingga 100.', 400);
        }

        // 3. ✨ LOGIKA VALIDASI MAKSIMAL 100% (VERSI BULLETPROOF) ✨
        // Kita tarik semua data tanpa filter khusus agar tidak ada yang terlewat
        $semuaUser = $userModel->findAll();

        $totalKomisiBerjalan = 0;
        foreach ($semuaUser as $user) {
            // Cek apakah dia Kasir DAN bukan kasir yang sedang diedit saat ini
            if (strcasecmp($user['role'], 'Kasir') == 0 && $user['id_user'] != $id) {
                // Pastikan data ini belum dihapus (Soft Delete)
                if (empty($user['deleted_at'])) {
                    $totalKomisiBerjalan += (float) $user['persentase_komisi'];
                }
            }
        }

        // Hitung total jika komisi baru ini ditambahkan
        $prediksiTotal = $totalKomisiBerjalan + (float) $persentaseBaru;

        // Jika melebihi 100, TOLAK dan kirim error 400 agar Flutter memunculkan SnackBar Merah
        if ($prediksiTotal > 100) {
            $sisaKuota = 100 - $totalKomisiBerjalan;
            return $this->respond([
                'status'  => 400,
                'message' => "Gagal! Total komisi melebihi 100% (Prediksi: {$prediksiTotal}%). Sisa maksimal yang bisa diatur adalah {$sisaKuota}%."
            ], 400);
        }

        // 4. Eksekusi Update
        $userModel->update($id, ['persentase_komisi' => $persentaseBaru]);

        // 5. Pencatatan Log Aktivitas
        $logModel = new LogAktivitasModel();
        $logModel->insert([
            'id_user'    => $userLogin['id_user'],
            'aksi'       => 'UPDATE_KOMISI',
            'keterangan' => 'Mengubah persentase komisi kasir ' . $kasirLama['nama_lengkap'] . ' menjadi ' . $persentaseBaru . '%'
        ]);

        return $this->respond([
            'status'  => 200,
            'message' => 'Persentase komisi kasir ' . $kasirLama['nama_lengkap'] . ' berhasil diperbarui.',
            'data'    => ['persentase_komisi' => $persentaseBaru]
        ]);
    }

    public function registerBiometric()
    {
        $userLogin = $this->getLoggedInUser();
        if (!$userLogin) return $this->failUnauthorized('Token tidak valid.');

        // Izinkan $biometric_token bernilai null agar bisa dihapus
        $biometric_token = $this->request->getVar('biometric_token');

        $userModel = new UserModel();

        // Jika token kosong/null, berarti aksi HAPUS
        $update = $userModel->update($userLogin['id_user'], [
            'biometric_token' => empty($biometric_token) ? null : $biometric_token
        ]);

        if ($update) {
            $logModel = new LogAktivitasModel();
            $logModel->insert([
                'id_user'    => $userLogin['id_user'],
                'aksi'       => empty($biometric_token) ? 'HAPUS_BIOMETRIC' : 'REGISTER_BIOMETRIC',
                'keterangan' => empty($biometric_token) ? 'Menghapus login biometrik.' : 'Mendaftarkan biometrik.'
            ]);

            return $this->respond([
                'status'  => 200,
                'message' => empty($biometric_token) ? 'Biometrik berhasil dihapus.' : 'Biometrik berhasil didaftarkan.'
            ]);
        } else {
            return $this->fail('Gagal memperbarui status biometrik.', 500);
        }
    }

    public function loginBiometric()
    {
        $biometric_token = $this->request->getVar('biometric_token');

        // Mencegah error jika HP mengirim null
        if (empty($biometric_token)) {
            return $this->fail('Token tidak valid.', 400);
        }

        $userModel = new UserModel();

        // Cari user yang tokennya SAMA dan TIDAK NULL (penting!)
        $user = $userModel->where('biometric_token', $biometric_token)
            ->where('biometric_token IS NOT NULL')
            ->first();

        if (!$user) {
            return $this->failUnauthorized('Perangkat tidak dikenali. Silakan login manual.');
        }

        $key = getenv('JWT_SECRET');
        $payload = [
            "iat"  => time(),
            "exp"  => time() + (60 * 60 * 24),
            "uid"  => $user['id_user'],
            "role" => $user['role']
        ];

        $token = JWT::encode($payload, $key, 'HS256');

        $logModel = new LogAktivitasModel();
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
                'nama_lengkap' => $user['nama_lengkap'],
                'role'         => $user['role']
            ]
        ]);
    }

    public function requestOtp()
    {
        $no_hp = $this->request->getVar('no_hp');
        if (!$no_hp) return $this->fail('Nomor WA wajib diisi.', 400);

        $userModel = new UserModel();
        $user = $userModel->where('no_hp', $no_hp)->first();

        if (!$user) return $this->failNotFound('Nomor WA tidak terdaftar.');

        $otp = rand(100000, 999999);
        $expiredAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $userModel->update($user['id_user'], [
            'otp_code' => $otp,
            'otp_expired_at' => $expiredAt
        ]);

        $tokenFonnte = getenv('FONNTE_TOKEN');
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
                'target' => $no_hp,
                'message' => $pesanWa,
                'countryCode' => '62',
            ),
            CURLOPT_HTTPHEADER => array(
                'Authorization: ' . $tokenFonnte
            ),
        ));

        curl_exec($curl);
        curl_close($curl);

        return $this->respond([
            'status'  => 200,
            'message' => 'Kode OTP berhasil dikirim ke WhatsApp Anda.'
        ]);
    }

    public function resetPasswordOtp()
    {
        $no_hp = $this->request->getVar('no_hp');
        $otp = $this->request->getVar('otp');
        $password_baru = $this->request->getVar('password_baru');

        if (!$no_hp || !$otp || !$password_baru) {
            return $this->fail('Nomor WA, OTP, dan Password Baru wajib diisi.', 400);
        }

        $userModel = new UserModel();
        $user = $userModel->where('no_hp', $no_hp)->first();

        if (!$user) return $this->failNotFound('Nomor WA tidak terdaftar.');

        if ($user['otp_code'] != $otp) {
            return $this->fail('Kode OTP salah.', 400);
        }

        $sekarang = date('Y-m-d H:i:s');
        if ($sekarang > $user['otp_expired_at']) {
            return $this->fail('Kode OTP sudah kadaluarsa. Silakan minta kode baru.', 400);
        }


        $userModel->update($user['id_user'], [
            'password'       => $password_baru,
            'otp_code'       => null,
            'otp_expired_at' => null
        ]);

        $logModel = new LogAktivitasModel();
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

    // Fitur Delete User ada di tahap selanjutnya sesuai instruksimu...
}
