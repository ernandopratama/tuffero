<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache pemeriksaan di Ringkasan Super Admin
    |--------------------------------------------------------------------------
    |
    | Pemeriksaan sistem, keamanan, pesanan macet, dan integrasi dihitung sekali lalu disimpan sekian detik,
    | supaya Ringkasan tidak menjalankannya di setiap muat. Halaman Pengaturan tidak memakai cache ini dan
    | selalu segar. 0 mematikan cache. "Cek ke API" dan "Tandai sudah ditangani" langsung membuang cache ini.
    |
    */

    'health_cache_seconds' => (int) env('DASHBOARD_HEALTH_CACHE_SECONDS', 60),

];
