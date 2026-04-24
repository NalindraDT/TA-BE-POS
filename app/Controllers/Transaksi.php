<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use Config\Database;

class Transaksi extends ResourceController
{
    protected $format = 'json';

    public function index()
    {
        $model = new TransaksiModel();
        $data = $model->orderBy('created_at', 'DESC')->findAll();

        return $this->respond($data);
    }

    public function show($id = null)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();

        $transaksi = $transaksiModel->find($id);

        if (!$transaksi) {
            return $this->failNotFound('Transaksi tidak ditemukan.');
        } else {
            $detail = $detailModel->select('detail_transaksi.*, produk.nama_produk')
                ->join('produk', 'produk.id_produk = detail_transaksi.id_produk', 'left')
                ->where('id_transaksi', $id)
                ->findAll();

            return $this->respond([
                'transaksi' => $transaksi,
                'detail'    => $detail
            ]);
        }
    }

    public function create()
    {
        $json = $this->request->getJSON();

        if (!$json) {
            return $this->fail('Data transaksi kosong.', 400);
        } else {
            $transaksiModel = new TransaksiModel();
            $detailModel = new DetailTransaksiModel();

            $db = Database::connect();
            $db->transStart();

            try {
                $dataTransaksi = [
                    'id_user'           => $json->id_user ?? null,
                    'kode_invoice'      => $transaksiModel->generateInvoice(),
                    'total_harga'       => $json->total_harga ?? 0,
                    'metode_pembayaran' => $json->metode_pembayaran ?? 'Tunai',
                    'tanggal_transaksi' => date('Y-m-d H:i:s')
                ];

                $transaksiModel->insert($dataTransaksi);
                $idTransaksiBaru = $transaksiModel->getInsertID();

                $details = $json->details ?? [];

                // Pengecekan keranjang kosong dipindah ke bentuk If-Else
                if (empty($details)) {
                    $db->transRollback(); // Wajib dibatalkan manual karena tidak pakai throw exception
                    return $this->fail("Keranjang belanja kosong!", 400);
                } else {
                    $dataDetailSiapInsert = [];
                    foreach ($details as $item) {
                        $dataDetailSiapInsert[] = [
                            'id_transaksi'     => $idTransaksiBaru,
                            'id_produk'        => $item->id_produk,
                            'kuantitas_produk' => $item->kuantitas_produk,
                            'harga_transaksi'  => $item->harga_transaksi,
                            'subtotal'         => $item->subtotal
                        ];
                    }

                    $detailModel->insertBatch($dataDetailSiapInsert);

                    $db->transComplete();

                    if ($db->transStatus() === false) {
                        return $this->fail('Gagal menyimpan transaksi ke database.', 500);
                    } else {
                        return $this->respondCreated([
                            'status'       => 201,
                            'message'      => 'Transaksi berhasil disimpan!',
                            'kode_invoice' => $dataTransaksi['kode_invoice'],
                            'id_transaksi' => $idTransaksiBaru
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $db->transRollback();
                return $this->fail($e->getMessage(), 400);
            }
        }
    }
}