<?php

namespace App\Services;

use App\Models\LoginDevice;
use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Peringatan masuk dari perangkat baru (D-090). Perangkat dikenali dari browser dan sistem operasi, bukan IP
 * (IP berganti saat pindah jaringan). Masuk pertama kali tidak diperingatkan: belum ada perangkat lain sebagai pembanding.
 */
class DeviceService
{
    public function __construct(private SessionService $sessions) {}

    public function recordLogin(User $user, Request $request): void
    {
        if ($user->getRoleNames()->isEmpty()) {
            return;
        }

        $device = $this->sessions->describe((string) $request->userAgent());
        $fingerprint = hash('sha256', $device['browser'].'|'.$device['os'].'|'.($device['mobile'] ? 'm' : 'd'));
        $known = LoginDevice::where('user_id', $user->id)->exists();

        $record = LoginDevice::firstOrCreate(
            ['user_id' => $user->id, 'fingerprint' => $fingerprint],
            ['browser' => $device['browser'], 'os' => $device['os'], 'ip_address' => $request->ip(), 'first_seen_at' => now(), 'last_seen_at' => now()],
        );

        if (! $record->wasRecentlyCreated) {
            $record->forceFill(['last_seen_at' => now(), 'ip_address' => $request->ip()])->save();

            return;
        }

        if ($known) {
            Notification::sendNow($user, new StaffEvent(
                'login_new_device',
                'security',
                $device['browser'].' di '.$device['os'],
                'Masuk dari perangkat baru',
                $device['browser'].' di '.$device['os'].', IP '.($request->ip() ?: 'tidak tercatat').'. Bukan Anda? Ganti password dan keluarkan perangkat itu di halaman Profil.',
            ));
        }
    }
}
