<?php

namespace App\Jobs;

use App\Services\SeoSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sinkron data Google untuk dashboard SEO (D-152 Fase 2): dijadwalkan harian dan dipakai tombol Perbarui sekarang. */
class SyncSeoData implements ShouldQueue
{
    use Queueable;

    /** PageSpeed membutuhkan puluhan detik per halaman. */
    public int $timeout = 600;

    public int $tries = 1;

    public function handle(SeoSyncService $sync): void
    {
        $sync->sync();
    }
}
