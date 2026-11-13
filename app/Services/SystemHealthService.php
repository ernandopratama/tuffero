<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pemeriksaan jalannya sistem untuk Super Admin: database, cache, penyimpanan, disk, konfigurasi, penjadwal, dan antrean.
 * Semua pemeriksaan lokal dan murah; tidak ada panggilan ke layanan luar, dan isi log, kunci, atau data pelanggan tidak ikut keluar.
 *
 * Penjadwal dan antrean dibuktikan lewat detak: tugas terjadwal yang menulis waktu ke cache tiap menit, dan job QueueHeartbeat
 * tiap 5 menit yang hanya dijalankan worker hidup. Tanpa detak, "tidak ada job menunggu" tidak membuktikan worker hidup.
 */
class SystemHealthService
{
    public const SCHEDULER_KEY = 'system:scheduler_heartbeat';

    public const QUEUE_KEY = 'system:queue_heartbeat';

    /** Detak lebih tua dari ini dianggap mati: penjadwal 1 menit sekali, antrean 5 menit sekali. */
    private const SCHEDULER_STALE = 5 * 60;

    private const QUEUE_STALE = 15 * 60;

    private const OLDEST_JOB_STALE = 10 * 60;

    /** @return list<array{key: string, label: string, status: string, detail: string}> */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->storage(),
            $this->disk(),
            $this->config(),
            $this->scheduler(),
            $this->queue(),
            $this->logErrors(),
        ];
    }

    /** PROPOSED: peringatan mulai 1 galat dalam 24 jam, masalah mulai 10. Batas ini belum diputuskan tim. */
    public const LOG_ERRORS_BAD = 10;

    public const LOG_ACK_KEY = 'system:log_errors_ack';

    /** Hanya ekor berkas log ini yang dibaca, supaya log besar tidak memperlambat halaman. */
    private const LOG_TAIL_BYTES = 512 * 1024;

    /**
     * Jumlah galat (ERROR sampai EMERGENCY) 24 jam terakhir di berkas log aplikasi. Hanya menghitung baris pembuka entri log;
     * isi pesan tidak dibaca keluar karena bisa memuat data pelanggan. WARNING dan di bawahnya tidak dihitung, begitu pula entri
     * dari environment lain (misalnya `testing`) yang menulis ke berkas yang sama.
     *
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function logErrors(): array
    {
        $path = (string) config('logging.channels.single.path', storage_path('logs/laravel.log'));
        $pattern = dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'*.log';
        $since = CarbonImmutable::now()->subDay();
        // Galat yang sudah ditandai ditangani tidak dihitung lagi; hanya yang terjadi sesudahnya.
        $ack = (int) Cache::get(self::LOG_ACK_KEY, 0);
        if ($ack > $since->getTimestamp()) {
            $since = CarbonImmutable::createFromTimestamp($ack);
        }
        // Nama saluran log = APP_ENV. Hanya entri environment ini yang dihitung, jadi galat sengaja dari `php artisan test`
        // (saluran `testing`, berkas log yang sama di lokal) tidak mengotori angka ini.
        $entry = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})[^\]]*\]\s+'.preg_quote((string) config('app.env'), '/').'\.(?:ERROR|CRITICAL|ALERT|EMERGENCY):/';
        $count = 0;
        $last = null;
        $truncated = false;

        foreach (glob($pattern) ?: [] as $file) {
            if (filemtime($file) < $since->getTimestamp()) {
                continue;
            }

            $handle = @fopen($file, 'rb');
            if (! $handle) {
                continue;
            }

            $size = filesize($file);
            if ($size > self::LOG_TAIL_BYTES) {
                fseek($handle, -self::LOG_TAIL_BYTES, SEEK_END);
                $truncated = true;
            }

            while (($line = fgets($handle)) !== false) {
                if (! preg_match($entry, $line, $match)) {
                    continue;
                }

                $at = CarbonImmutable::parse($match[1], config('app.timezone'));
                if ($at->gte($since)) {
                    $count++;
                    $last = $last === null || $at->gt($last) ? $at : $last;
                }
            }

            fclose($handle);
        }

        $handled = $ack > 0 && $ack >= CarbonImmutable::now()->subDay()->getTimestamp() ? ' Dihitung sejak ditandai ditangani '.$this->ago($ack).'.' : '';

        if ($count === 0) {
            return $this->check('log_errors', 'Galat aplikasi (24 jam)', 'ok', 'Tidak ada galat di log.'.$handled);
        }

        return $this->check(
            'log_errors',
            'Galat aplikasi (24 jam)',
            $count >= self::LOG_ERRORS_BAD ? 'bad' : 'warn',
            "$count galat di log, terakhir ".$this->ago($last->getTimestamp()).($truncated ? ' (dihitung dari 512 KB terakhir)' : '').'. Buka storage/logs di server untuk isinya.'.$handled,
        );
    }

    /**
     * Tandai galat log yang ada sekarang sebagai sudah ditangani: hanya galat sesudah saat ini yang dihitung. Berkas log tidak diubah
     * atau dihapus, jadi riwayatnya tetap ada di server. Disimpan di cache, tanpa tabel baru.
     */
    public function acknowledgeLogErrors(): void
    {
        Cache::forever(self::LOG_ACK_KEY, time());
        Cache::forget(DashboardService::HEALTH_CACHE_KEY);
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return $this->check('database', 'Database', 'ok', 'Terhubung');
        } catch (Throwable) {
            return $this->check('database', 'Database', 'bad', 'Tidak bisa terhubung ke database');
        }
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function cache(): array
    {
        try {
            Cache::put('system:health_ping', 'pong', 10);

            return Cache::get('system:health_ping') === 'pong'
                ? $this->check('cache', 'Cache', 'ok', 'Bisa ditulis dan dibaca')
                : $this->check('cache', 'Cache', 'bad', 'Nilai yang ditulis tidak terbaca kembali');
        } catch (Throwable) {
            return $this->check('cache', 'Cache', 'bad', 'Cache tidak bisa dipakai');
        }
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function storage(): array
    {
        $blocked = collect([storage_path('app'), storage_path('logs'), storage_path('framework/cache')])->reject(fn (string $path) => is_dir($path) && is_writable($path));

        return $blocked->isEmpty()
            ? $this->check('storage', 'Penyimpanan', 'ok', 'Folder storage bisa ditulis')
            : $this->check('storage', 'Penyimpanan', 'bad', 'Folder tidak bisa ditulis: '.$blocked->map(fn (string $path) => basename(dirname($path)).'/'.basename($path))->implode(', '));
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function disk(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if (! $free || ! $total) {
            return $this->check('disk', 'Ruang disk', 'warn', 'Sisa ruang tidak bisa dibaca');
        }

        $percent = (int) round($free / $total * 100);
        $detail = sprintf('Sisa %d%% (%.1f GB dari %.1f GB)', $percent, $free / 1024 ** 3, $total / 1024 ** 3);

        return $this->check('disk', 'Ruang disk', $percent < 5 ? 'bad' : ($percent < 15 ? 'warn' : 'ok'), $detail);
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function config(): array
    {
        $production = app()->isProduction();

        if ($production && config('app.debug')) {
            return $this->check('config', 'Konfigurasi', 'bad', 'APP_DEBUG menyala di produksi, galat bisa membocorkan data');
        }

        return $this->check('config', 'Konfigurasi', 'ok', 'APP_ENV='.app()->environment().', debug '.(config('app.debug') ? 'menyala' : 'mati'));
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function scheduler(): array
    {
        $beat = (int) Cache::get(self::SCHEDULER_KEY, 0);

        if ($beat === 0) {
            return $this->missing('scheduler', 'Penjadwal', 'Belum ada detak. Pastikan cron `php artisan schedule:run` tiap menit berjalan (lokal: `composer dev` atau `php artisan schedule:work`). Tanpa ini pesanan tidak kedaluwarsa dan stok tertahan.');
        }

        $age = time() - $beat;

        return $age > self::SCHEDULER_STALE
            ? $this->check('scheduler', 'Penjadwal', 'bad', 'Detak terakhir '.$this->ago($beat).'. Penjadwal berhenti: pesanan tidak kedaluwarsa dan stok tertahan.')
            : $this->check('scheduler', 'Penjadwal', 'ok', 'Berjalan, detak terakhir '.$this->ago($beat));
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function queue(): array
    {
        $beat = (int) Cache::get(self::QUEUE_KEY, 0);
        $stats = $this->queueStats();
        $issues = [];
        $status = 'ok';

        if ($beat === 0) {
            return $this->missing('queue', 'Antrean', 'Belum ada detak. Pastikan `php artisan queue:work` berjalan di bawah Supervisor, dan penjadwal aktif karena detak antrean dikirim oleh penjadwal.'.$this->queueSuffix($stats));
        }

        if (time() - $beat > self::QUEUE_STALE) {
            $status = 'bad';
            $issues[] = 'Detak terakhir '.$this->ago($beat).'. Worker antrean berhenti.';
        }

        if ($stats && $stats['oldest'] !== null && time() - $stats['oldest'] > self::OLDEST_JOB_STALE) {
            $status = 'bad';
            $issues[] = 'Job tertua menunggu '.$this->ago($stats['oldest']).'.';
        }

        if ($stats && $stats['failed'] > 0) {
            $status = $status === 'bad' ? 'bad' : 'warn';
            $issues[] = $stats['failed'].' job gagal (tabel failed_jobs).';
        }

        $detail = $issues ? implode(' ', $issues) : 'Worker berjalan, detak terakhir '.$this->ago($beat).'.';

        return $this->check('queue', 'Antrean', $status, $detail.$this->queueSuffix($stats));
    }

    /** @return array{pending: int, oldest: ?int, failed: int}|null null bila antrean bukan driver database. */
    private function queueStats(): ?array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
            return null;
        }

        $ready = DB::table('jobs')->where('available_at', '<=', time());

        return [
            'pending' => (clone $ready)->count(),
            'oldest' => ($oldest = (clone $ready)->min('available_at')) ? (int) $oldest : null,
            'failed' => DB::table('failed_jobs')->count(),
        ];
    }

    /** @param array{pending: int, oldest: ?int, failed: int}|null $stats */
    private function queueSuffix(?array $stats): string
    {
        return $stats ? " Menunggu: {$stats['pending']}, gagal: {$stats['failed']}." : '';
    }

    /** Tanpa detak sama sekali: lokal hanya peringatan (worker sering memang tidak jalan), selain itu masalah. */
    private function missing(string $key, string $label, string $detail): array
    {
        return $this->check($key, $label, app()->environment('local') ? 'warn' : 'bad', $detail);
    }

    private function ago(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp)->locale('id')->diffForHumans();
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function check(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
