<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id_user';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'username',
        'password',
        'nama_lengkap',
        'role',
        'is_active',
        'no_hp',
        'foto_profile',
        'pin_hash',
        'biometric_token',
        'persentase_komisi',
        'otp_code',  
        'otp_expired_at'
    ];

    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $beforeInsert = ['hashPassword'];
    protected $beforeUpdate = ['hashPassword'];


    protected function hashPassword(array $data)
    {

        if (isset($data['data']['password'])) {
            $data['data']['password'] = password_hash($data['data']['password'], PASSWORD_DEFAULT);
        }
        if (isset($data['data']['pin_hash'])) {
            $data['data']['pin_hash'] = password_hash((string) $data['data']['pin_hash'], PASSWORD_DEFAULT);
        }
        return $data;
    }
}