<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Pemeriksaan Cloudflare Turnstile di server. Di luar local dan testing, rahasia kosong atau gangguan jaringan berarti ditolak.
 */
class TurnstileService
{
    public function passes(?string $token, string $ip): bool
    {
        $secret = config('services.turnstile.secret');

        if (blank($secret)) {
            return app()->environment('local', 'testing');
        }

        if (blank($token)) {
            return false;
        }

        try {
            return Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ])->json('success') === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
