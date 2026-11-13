<?php

namespace App\Jobs;

use App\Services\SystemHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dijadwalkan tiap 5 menit. Hanya worker yang hidup yang menjalankannya, jadi waktu terakhirnya membuktikan `queue:work` berjalan.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(SystemHealthService::QUEUE_KEY, now()->timestamp, now()->addDay());
    }
}
