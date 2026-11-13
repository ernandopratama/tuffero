<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ringkasan Super Admin menyimpan hasil pemeriksaan 60 detik. Uji mengubah konfigurasi di tengah jalan dan membaca ulang,
        // jadi cache itu dimatikan di sini; ia diuji sendiri di DashboardHealthCacheTest.
        config(['dashboard.health_cache_seconds' => 0]);
    }
}
