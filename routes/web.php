<?php

use App\Http\Controllers\Store\GoogleAuthController;
use App\Http\Controllers\Store\StorefrontController;
use App\Http\Middleware\SetStoreContext;
use Illuminate\Support\Facades\Route;

// Area admin: Vue 3 SPA dengan Blade sendiri (D-053). CSS Bootstrap template toko menimpa token design system.
Route::view('/login', 'admin');
Route::view('/lupa-kata-sandi', 'admin');
Route::view('/admin/{any?}', 'admin')->where('any', '.*');

Route::get('/sitemap.xml', [StorefrontController::class, 'sitemap']);

// Masuk pelanggan dengan Google. Hanya pengunjung yang belum masuk; dibatasi agar tidak dipakai membanjiri Google atau log.
Route::middleware(['store.account:guest', 'throttle:20,1'])->prefix('auth/google')->controller(GoogleAuthController::class)->group(function () {
    Route::get('/', 'redirect');
    Route::get('/callback', 'callback');
});

// Halaman toko: Blade + Alpine (D-053).
Route::middleware(SetStoreContext::class)->controller(StorefrontController::class)->group(function () {
    Route::get('/', 'home');
    Route::get('/tentang-kami', 'about');
    Route::get('/layanan', 'services');
    Route::get('/galeri', 'gallery');
    Route::get('/kontak', 'contact');
    Route::get('/produk', 'catalog');
    Route::get('/produk/{sku}', 'product');
    Route::get('/voucher', 'vouchers');
    Route::get('/promo', 'promos');
    Route::get('/promo/{slug}', 'promo');
    Route::get('/keranjang', 'cart');
    Route::get('/checkout', 'checkout');
    Route::get('/pesanan/{ref}', 'order');
    Route::get('/cek-pesanan', 'lookup');
    Route::get('/ajukan-refund', 'refund');

    Route::middleware('store.account:guest')->group(function () {
        Route::get('/masuk', 'signIn');
        Route::get('/daftar', 'register');
        // Data verifikasi hanya ada di memori browser; membuka alamat ini langsung selalu kembali ke daftar.
        Route::redirect('/masuk/verifikasi', '/daftar');
    });

    Route::middleware('store.account:customer')->group(function () {
        Route::get('/akun', 'account');
        Route::get('/akun/pesanan', 'accountOrders');
        Route::get('/akun/alamat', 'accountAddresses');
        Route::get('/akun/voucher', 'accountVouchers');
    });

    Route::fallback('missing');
});
