<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\UserModel;

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
                if (!password_verify($password, $user['password'])) {
                    return $this->failUnauthorized('Password salah.');
                } else {
                    unset($user['password']);
                    unset($user['pin_hash']);

                    return $this->respond([
                        'status'   => 200,
                        'message'  => 'Login Berhasil',
                        'data'     => $user
                    ]);
                }
            }
        }
    }

    //menambahkan nested kode (if else else)

    public function index()
    {
        $model = new UserModel();
        $users = $model->select('id_user, username, nama_lengkap, role, biometric_token, created_at, updated_at')->findAll();
        
        return $this->respond($users);
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

        $rules = [
            'username'     => "required|alpha_numeric|is_unique[users.username,id_user,{$id}]",
            'nama_lengkap' => 'required|string',
            'role'         => 'required|in_list[Admin,Kasir,Owner]'
        ];

        $json = $this->request->getJSON();
        
        if (!$json) {
            return $this->fail('Body JSON kosong.', 400);
        } else {
            if (!$model->find($id)) {
                return $this->failNotFound('User tidak ditemukan.');
            } else {
                $data = [
                    'username'     => $json->username ?? null,
                    'nama_lengkap' => $json->nama_lengkap ?? null,
                    'role'         => $json->role ?? null
                ];

                if (!empty($json->password)) {
                    $rules['password'] = 'min_length[6]';
                    $data['password']  = $json->password;
                }

                if (!$this->validateData($data, $rules)) {
                    return $this->failValidationErrors($this->validator->getErrors());
                } else {
                    $model->update($id, $data);
                    unset($data['password']);

                    return $this->respond([
                        'status'   => 200,
                        'messages' => ['success' => 'Data user berhasil diperbarui.'],
                        'data'     => $data
                    ]);
                }
            }
        }
    }

    public function delete($id = null)
    {
        $model = new UserModel();

        if (!$model->find($id)) {
            return $this->failNotFound('User tidak ditemukan.');
        } else {
            $model->delete($id);

            return $this->respondDeleted([
                'status'   => 200,
                'messages' => ['success' => 'User berhasil dihapus.']
            ]);
        }
    }
}