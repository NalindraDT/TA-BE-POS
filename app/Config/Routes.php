<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// ==========================================
// 🌍 RUTE PUBLIK (Bebas Akses Tanpa Token)
// ==========================================
$routes->post('user/login', 'User::login');
$routes->post('payment/webhook', 'Payment::webhook');
$routes->post('user/login-biometric', 'User::loginBiometric');

$routes->post('user/request-otp', 'User::requestOtp');
$routes->post('user/reset-password-otp', 'User::resetPasswordOtp');


// ==========================================
// 🛡️ RUTE TERLINDUNGI (Wajib Bawa Token JWT)
// ==========================================
// Semua rute di dalam grup ini otomatis dijaga ketat oleh filter JWT
$routes->group('', ['filter' => 'jwt'], function ($routes) {

    // ----------------------------------------
    // 👤 MANAJEMEN USER & OTENTIKASI
    // ----------------------------------------
    $routes->post('user/register-biometric', 'User::registerBiometric');
    $routes->post('user/logout', 'User::logout');
    $routes->get('user/profile', 'User::profile');
    $routes->put('user/update-biodata', 'User::updateBiodata');
    $routes->post('user/update-photo', 'User::updatePhoto');
    $routes->put('user/update-security', 'User::updateSecurity');
    $routes->post('user/(:num)/reset-password', 'User::resetPassword/$1');
    $routes->post('user/(:num)/verify-pin', 'User::verifyPin/$1');

    // ----------------------------------------
    // 💼 MANAJEMEN KASIR (Untuk Owner/Admin)
    // ----------------------------------------
    $routes->get('user/list-kasir', 'User::getListKasir');
    $routes->put('user/update-komisi/(:num)', 'User::updateKomisi/$1');

    // ----------------------------------------
    // 📊 DASHBOARD, LAPORAN & AKTIVITAS
    // ----------------------------------------
    $routes->get('dashboard', 'Dashboard::index');
    $routes->get('laporan', 'Laporan::index');
    $routes->get('log-aktivitas', 'LogAktivitas::index');

    // ----------------------------------------
    // 🛒 TRANSAKSI & PEMBAYARAN MIDTRANS
    // ----------------------------------------
    $routes->post('payment/create', 'Payment::createTransaction');
    $routes->put('transaksi/pelunasan/(:num)', 'Transaksi::pelunasanManual/$1');

    // ----------------------------------------
    // 📦 KUSTOM RUTE PRODUK
    // ----------------------------------------
    $routes->put('produk/status/(:num)', 'Produk::updateStatus/$1'); // 👈 Celah keamanan sudah ditutup!

    // ----------------------------------------
    // ⚙️ RUTE RESOURCE (CRUD OTOMATIS)
    // ----------------------------------------
    // Resource otomatis meng-handle method GET, POST, PUT, DELETE
    $routes->resource('kategori');
    $routes->resource('produk');
    $routes->resource('transaksi');
    $routes->resource('user');

});