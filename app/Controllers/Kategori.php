<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\KategoriModel;

class Kategori extends ResourceController
{
    protected $format = 'json';

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
        $model = new KategoriModel();

        $rules = [
            'nama_kategori' => "required|min_length[3]|is_unique[kategori.nama_kategori,id_kategori,{$id}]",
            'deskripsi'     => 'permit_empty|string'
        ];

        $json = $this->request->getJSON();
        
        if (!$json) {
            return $this->fail('Body JSON kosong atau format salah.', 400);
        } else {
            if (!$model->find($id)) {
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
        $model = new KategoriModel();

        if (!$model->find($id)) {
            return $this->failNotFound('Data kategori tidak ditemukan.');
        } else {
            $model->delete($id);

            return $this->respondDeleted([
                'status'   => 200,
                'messages' => ['success' => 'Kategori berhasil dihapus.']
            ]);
        }
    }
}