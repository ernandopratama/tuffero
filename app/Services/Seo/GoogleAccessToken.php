<?php

namespace App\Services\Seo;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Token akses Search Console dari service account (paket resmi google/auth), cakupan baca-saja.
 * Berkas kunci dibaca dari GOOGLE_SC_CREDENTIALS_PATH di server; isinya tidak pernah disimpan, dicatat, atau dikirim ke dashboard.
 * Token berlaku 60 menit dan disimpan 50 menit di cache.
 */
class GoogleAccessToken
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private const CACHE_KEY = 'seo:google-token';

    /** Properti dan berkas kunci sama-sama terisi dan berkasnya terbaca. */
    public function configured(): bool
    {
        $path = (string) config('services.google_seo.credentials_path');

        return filled(config('services.google_seo.property')) && $path !== '' && is_file($path) && is_readable($path);
    }

    public function token(): string
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(50), function (): string {
            $credentials = new ServiceAccountCredentials(self::SCOPE, (string) config('services.google_seo.credentials_path'));
            $token = $credentials->fetchAuthToken();

            return $token['access_token'] ?? throw new RuntimeException('Google tidak mengembalikan token akses.');
        });
    }
}
