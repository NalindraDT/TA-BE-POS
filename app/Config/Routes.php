<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->resource('kategori');
$routes->resource('user');
$routes->resource('produk');
$routes->resource('transaksi');

$routes->post('login', 'User::login');