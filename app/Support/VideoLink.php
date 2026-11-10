<?php

namespace App\Support;

/**
 * Mengenali platform dan ID video dari tautan yang sudah lolos `AllowedVideoUrl`. Hanya mengurai teks URL;
 * tidak pernah membuka tautan (D-042). ID kosong berarti bentuk tautan belum dikenali (mis. tautan pendek vm.tiktok.com).
 */
class VideoLink
{
    /** @return array{provider: string|null, external_id: string|null} */
    public static function parse(string $url): array
    {
        $parts = parse_url($url) ?: [];
        $host = strtolower(preg_replace('/^(www|m)\./i', '', $parts['host'] ?? '') ?? '');
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn ($s) => $s !== ''));
        parse_str($parts['query'] ?? '', $query);

        [$provider, $id] = match (true) {
            $host === 'youtu.be' => ['youtube', $segments[0] ?? null],
            $host === 'youtube.com' => ['youtube', in_array($segments[0] ?? '', ['shorts', 'embed', 'live'], true) ? ($segments[1] ?? null) : (is_string($query['v'] ?? null) ? $query['v'] : null)],
            $host === 'tiktok.com' => ['tiktok', ($segments[1] ?? '') === 'video' ? ($segments[2] ?? null) : null],
            $host === 'vm.tiktok.com' => ['tiktok', null],
            $host === 'instagram.com' => ['instagram', in_array($segments[0] ?? '', ['p', 'reel', 'reels', 'tv'], true) ? ($segments[1] ?? null) : null],
            default => [null, null],
        };

        return ['provider' => $provider, 'external_id' => is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : null];
    }
}
