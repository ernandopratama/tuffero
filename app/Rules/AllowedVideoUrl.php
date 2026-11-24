<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Tautan video konten promo (D-042). Host dicocokkan persis setelah URL diurai; server tidak pernah mengambil isi URL ini.
 * Dengan `$platform` (mis. catatan metrik konten), hanya host platform itu yang diterima.
 */
class AllowedVideoUrl implements ValidationRule
{
    private const PLATFORM_HOSTS = [
        'youtube' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
        'tiktok' => ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com'],
        'instagram' => ['instagram.com', 'www.instagram.com'],
    ];

    /** Tautan berbagi dari aplikasi; hanya diterima bila platform ditentukan (catatan metrik), bukan untuk konten promo. */
    private const SHARE_HOSTS = ['tiktok' => ['vt.tiktok.com'], 'instagram' => ['instagr.am']];

    private const LABELS = ['youtube' => 'YouTube', 'tiktok' => 'TikTok', 'instagram' => 'Instagram'];

    public function __construct(private ?string $platform = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) && ! str_contains($value, '\\') && filter_var($value, FILTER_VALIDATE_URL)
            ? parse_url($value)
            : false;

        $hosts = $this->platform === null ? array_merge(...array_values(self::PLATFORM_HOSTS)) : array_merge(self::PLATFORM_HOSTS[$this->platform] ?? [], self::SHARE_HOSTS[$this->platform] ?? []);

        $valid = is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), $hosts, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && trim($parts['path'] ?? '', '/') !== '';

        if (! $valid) {
            $fail($this->platform === null
                ? 'Gunakan tautan https video YouTube, TikTok, atau Instagram.'
                : 'Gunakan tautan https posting '.(self::LABELS[$this->platform] ?? $this->platform).'.');
        }
    }
}
