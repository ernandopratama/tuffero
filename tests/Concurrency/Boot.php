<?php

namespace Tests\Concurrency;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyalakan aplikasi Laravel terhadap database KHUSUS tes konkurensi (MySQL 8.4 atau MariaDB 10.6), bukan SQLite.
 *
 * Penjaga keselamatan: tes ini menjalankan migrate:fresh dan menghapus tabel pesanan/stok, jadi ia menolak berjalan kecuali
 * host adalah mesin lokal dan nama database berbentuk tuffero_concurrency_test (atau diberi akhiran _huruf/angka).
 * Nama database dev, staging, dan production tidak akan lolos. Dipakai bersama oleh proses tes dan proses worker.
 */
final class Boot
{
    public const DATABASE_PATTERN = '/^tuffero_concurrency_test(_[a-z0-9]+)?$/';

    public const SAFE_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    private static ?Application $app = null;

    /** Pengaturan koneksi dari variabel lingkungan CONCURRENCY_DB_*, atau null bila tes tidak dikonfigurasi (tes dilewati). */
    public static function settings(): ?array
    {
        $database = getenv('CONCURRENCY_DB_DATABASE');

        if ($database === false || $database === '') {
            return null;
        }

        $host = getenv('CONCURRENCY_DB_HOST') ?: '127.0.0.1';

        if (! in_array($host, self::SAFE_HOSTS, true)) {
            throw new RuntimeException("Tes konkurensi dihentikan: host \"{$host}\" bukan mesin lokal. Hanya ".implode(', ', self::SAFE_HOSTS).' yang diizinkan.');
        }

        if (! preg_match(self::DATABASE_PATTERN, $database)) {
            throw new RuntimeException("Tes konkurensi dihentikan: database \"{$database}\" bukan database khusus tes. Namanya harus tuffero_concurrency_test (boleh diberi akhiran _huruf/angka).");
        }

        return [
            'host' => $host,
            'port' => getenv('CONCURRENCY_DB_PORT') ?: '3306',
            'database' => $database,
            'username' => getenv('CONCURRENCY_DB_USERNAME') ?: 'root',
            'password' => getenv('CONCURRENCY_DB_PASSWORD') ?: '',
        ];
    }

    /**
     * Variabel lingkungan untuk aplikasi. Variabel proses menang atas .env (Dotenv tidak menimpa), jadi .env tidak dibaca
     * untuk koneksi database dan tidak bisa mengarahkan tes ini ke database lain.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $settings = self::settings() ?? throw new RuntimeException('CONCURRENCY_DB_DATABASE belum diatur.');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => $settings['host'],
            'DB_PORT' => (string) $settings['port'],
            'DB_DATABASE' => $settings['database'],
            'DB_USERNAME' => $settings['username'],
            'DB_PASSWORD' => $settings['password'],
            'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'null',
            'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];
    }

    public static function app(): Application
    {
        if (self::$app) {
            return self::$app;
        }

        foreach (self::environment() as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // Cache konfigurasi membuat variabel di atas diabaikan; koneksi bisa menunjuk database asli.
        if ($app->configurationIsCached()) {
            throw new RuntimeException('Tes konkurensi dihentikan: konfigurasi ter-cache. Jalankan php artisan config:clear.');
        }

        $database = (string) DB::connection()->selectOne('select database() as name')->name;

        if (config('database.default') !== 'mysql' || ! preg_match(self::DATABASE_PATTERN, $database) || $database !== self::settings()['database']) {
            throw new RuntimeException("Tes konkurensi dihentikan: koneksi mengarah ke \"{$database}\", bukan database khusus tes.");
        }

        // Tanpa panggilan HTTP keluar dan tanpa berbagi kunci cache dengan penyimpanan dev.
        config([
            'services.midtrans.server_key' => null,
            'services.midtrans.client_key' => null,
            'cache.default' => 'file',
            'cache.stores.file.path' => sys_get_temp_dir().'/tuffero-concurrency-cache',
            'cache.stores.file.lock_path' => sys_get_temp_dir().'/tuffero-concurrency-cache',
        ]);

        return self::$app = $app;
    }
}
