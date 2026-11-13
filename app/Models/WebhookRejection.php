<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['provider', 'reason', 'ip_address'])]
class WebhookRejection extends Model
{
    public const UPDATED_AT = null;

    /** Hari penyimpanan; baris lebih lama dihapus oleh jadwal harian. */
    public const KEEP_DAYS = 30;

    /**
     * Satu catatan per penyedia, alasan, dan IP tiap menit, supaya banjir permintaan palsu tidak membengkakkan tabel.
     * Jadi angka di dashboard menghitung menit-IP yang ditolak, bukan setiap permintaan.
     */
    public static function record(string $provider, string $reason): void
    {
        $ip = request()->ip();

        if (! Cache::add("webhook-rejection:{$provider}:{$reason}:{$ip}", 1, 60)) {
            return;
        }

        self::create(['provider' => $provider, 'reason' => $reason, 'ip_address' => $ip]);
    }
}
