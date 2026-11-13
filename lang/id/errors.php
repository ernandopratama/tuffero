<?php

// Teks halaman galat toko (404, 403, 419, 429, 500, 503). Admin memakai teks Indonesia sendiri di Vue.
return [
    'home' => 'Ke Beranda',
    'products' => 'Lihat Produk',
    'cart' => 'Lihat Keranjang',
    'reload' => 'Coba lagi',
    'code' => 'Kode galat :code',
    'retry_in' => 'Coba lagi dalam :seconds detik.',

    '404' => [
        'title' => 'Halaman tidak ditemukan',
        'text' => 'Alamat yang Anda tuju tidak ada atau sudah dipindahkan. Yuk, kembali ke toko dan lanjut belanja.',
    ],
    '403' => [
        'title' => 'Akses ditolak',
        'text' => 'Anda tidak punya izin untuk membuka halaman ini.',
    ],
    '419' => [
        'title' => 'Sesi sudah berakhir',
        'text' => 'Halaman ini terlalu lama terbuka sehingga pengamannya kedaluwarsa. Buka halamannya lagi, lalu ulangi.',
    ],
    '429' => [
        'title' => 'Terlalu banyak permintaan',
        'text' => 'Mohon tunggu sebentar sebelum mencoba lagi.',
    ],
    '500' => [
        'title' => 'Ada yang tidak beres',
        'text' => 'Maaf, ada gangguan di sisi kami. Coba lagi sebentar lagi.',
    ],
    '503' => [
        'title' => 'Sedang dipercantik',
        'text' => 'Tuffero sedang dalam perawatan singkat. Kami segera kembali.',
    ],
];
