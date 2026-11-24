<?php

namespace App\Services\Seo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klien Search Console API v3 (baca-saja): Search Analytics dan daftar sitemap. Properti domain ditulis
 * `sc-domain:tuffero.com` dan di-URL-encode di jalur. Bentuk respons mengikuti dokumentasi Google.
 */
class SearchConsoleClient
{
    private const BASE = 'https://www.googleapis.com/webmasters/v3/sites/';

    private const ROW_LIMIT = 25000;

    /** Batas halaman (permintaan) per kueri, supaya data besar tidak menahan sinkron tanpa akhir. */
    private const MAX_PAGES = 4;

    public function __construct(private GoogleAccessToken $token) {}

    /**
     * Baris Search Analytics (type web, hanya data final). Tanggal permintaan dan hasil memakai zona waktu Pasifik.
     * `$dimensions` mis. ['date'] atau ['date', 'page'].
     *
     * @param  list<string>  $dimensions
     * @return list<array{keys: list<string>, clicks: float|int, impressions: float|int, ctr: float|int, position: float|int}>
     */
    public function query(string $startDate, string $endDate, array $dimensions): array
    {
        $rows = [];

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $page = $this->request()->post($this->url('searchAnalytics/query'), [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => $dimensions,
                'type' => 'web',
                'dataState' => 'final',
                'rowLimit' => self::ROW_LIMIT,
                'startRow' => $i * self::ROW_LIMIT,
            ])->throw()->json('rows') ?? [];

            $rows = [...$rows, ...$page];
            if (count($page) < self::ROW_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Sitemap yang terdaftar di properti. Isi tiap elemen tidak dijelaskan di dokumentasi, jadi pembaca hanya memakai `path`.
     *
     * @return list<array<string, mixed>>
     */
    public function sitemaps(): array
    {
        return $this->request()->get($this->url('sitemaps'))->throw()->json('sitemap') ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token->token())->acceptJson()->timeout(60);
    }

    private function url(string $path): string
    {
        return self::BASE.rawurlencode((string) config('services.google_seo.property')).'/'.$path;
    }
}
