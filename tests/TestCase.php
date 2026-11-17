<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Penjaga: RefreshDatabase menjalankan migrate:fresh. Bila cache konfigurasi (config:cache atau optimize) membuat uji
     * mengabaikan phpunit.xml, uji akan mengosongkan database asli. Dicek sebelum trait uji jalan, jadi berhenti lebih dulu.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException("Uji dihentikan: koneksi \"{$connection}\" bukan SQLite in-memory. Jalankan php artisan config:clear lalu ulangi.");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ringkasan Super Admin menyimpan hasil pemeriksaan 60 detik. Uji mengubah konfigurasi di tengah jalan dan membaca ulang,
        // jadi cache itu dimatikan di sini; ia diuji sendiri di DashboardHealthCacheTest.
        config(['dashboard.health_cache_seconds' => 0]);
    }
}
