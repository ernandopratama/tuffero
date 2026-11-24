<?php

namespace App\Jobs;

use App\Models\CustomerNotification;
use App\Services\CustomerWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Mengirim satu pesan WhatsApp pelanggan. Hanya id baris yang masuk antrean: token Meta dan nomor tujuan dibaca saat job
 * berjalan, jadi tidak tersimpan di tabel `jobs` atau `failed_jobs`.
 */
class SendCustomerWhatsApp implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $notificationId) {}

    public function handle(CustomerWhatsApp $whatsapp): void
    {
        $whatsapp->deliver(CustomerNotification::with('order')->findOrFail($this->notificationId));
    }

    public function failed(Throwable $e): void
    {
        CustomerNotification::whereKey($this->notificationId)->update(['status' => CustomerNotification::FAILED, 'error' => mb_substr(class_basename($e), 0, 60)]);
    }
}
