<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Tautan video konten promo (D-042). Host dicocokkan persis setelah URL diurai; server tidak pernah mengambil isi URL ini.
 */
class AllowedVideoUrl implements ValidationRule
{
    private const HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be',
        'tiktok.com', 'www.tiktok.com', 'vm.tiktok.com',
        'instagram.com', 'www.instagram.com',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) && ! str_contains($value, '\\') && filter_var($value, FILTER_VALIDATE_URL)
            ? parse_url($value)
            : false;

        $valid = is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && trim($parts['path'] ?? '', '/') !== '';

        if (! $valid) {
            $fail('Gunakan tautan https video YouTube, TikTok, atau Instagram.');
        }
    }
}
