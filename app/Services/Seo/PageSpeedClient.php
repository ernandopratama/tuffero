<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien PageSpeed Insights API v5 (strategi mobile, kategori performa). Kunci API dari GOOGLE_PAGESPEED_API_KEY.
 * Google berencana menghentikan data pengguna nyata (CrUX) di API ini, jadi kolom `field_*` boleh kosong.
 */
class PageSpeedClient
{
    private const ENDPOINT = 'https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function configured(): bool
    {
        return filled(config('services.google_seo.pagespeed_key'));
    }

    /**
     * @return array{score: ?int, lcp_ms: ?int, cls: ?float, tbt_ms: ?int, field_lcp_ms: ?int, field_inp_ms: ?int, field_cls: ?float, field_category: ?string, field_scope: ?string}
     */
    public function run(string $url): array
    {
        $json = Http::timeout(120)->acceptJson()->get(self::ENDPOINT, [
            'url' => $url,
            'strategy' => 'MOBILE',
            'category' => 'PERFORMANCE',
            'key' => config('services.google_seo.pagespeed_key'),
        ])->throw()->json();

        if ($error = data_get($json, 'lighthouseResult.runtimeError.message')) {
            throw new RuntimeException('Lighthouse gagal: '.$error);
        }

        $audit = fn (string $id) => data_get($json, "lighthouseResult.audits.$id.numericValue");
        $int = fn ($value) => is_numeric($value) ? (int) round((float) $value) : null;
        $score = data_get($json, 'lighthouseResult.categories.performance.score');

        // Data pengguna nyata: tingkat halaman bila ada, kalau tidak tingkat situs. Persentil ke-75.
        $field = $json['loadingExperience'] ?? null;
        $scope = ($field['origin_fallback'] ?? false) ? 'origin' : 'page';
        if (empty($field['metrics'])) {
            $field = $json['originLoadingExperience'] ?? null;
            $scope = 'origin';
        }
        $hasField = ! empty($field['metrics']);
        // Persentil CLS dari Google berskala 100 kali nilai CLS (5 berarti 0,05).
        $cls = data_get($field, 'metrics.CUMULATIVE_LAYOUT_SHIFT_SCORE.percentile');

        return [
            'score' => is_numeric($score) ? (int) round((float) $score * 100) : null,
            'lcp_ms' => $int($audit('largest-contentful-paint')),
            'cls' => is_numeric($audit('cumulative-layout-shift')) ? round((float) $audit('cumulative-layout-shift'), 3) : null,
            'tbt_ms' => $int($audit('total-blocking-time')),
            'field_lcp_ms' => $hasField ? $int(data_get($field, 'metrics.LARGEST_CONTENTFUL_PAINT_MS.percentile')) : null,
            'field_inp_ms' => $hasField ? $int(data_get($field, 'metrics.INTERACTION_TO_NEXT_PAINT.percentile')) : null,
            'field_cls' => $hasField && is_numeric($cls) ? round((float) $cls / 100, 3) : null,
            'field_category' => $hasField ? (is_string($field['overall_category'] ?? null) ? substr($field['overall_category'], 0, 20) : null) : null,
            'field_scope' => $hasField ? $scope : null,
        ];
    }
}
