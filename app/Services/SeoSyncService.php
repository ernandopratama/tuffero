<?php

namespace App\Services;

use App\Services\Seo\GoogleAccessToken;
use App\Services\Seo\PageSpeedClient;
use App\Services\Seo\SearchConsoleClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menarik data Google untuk dashboard SEO (D-152 Fase 2): Search Console (total harian, 50 halaman dan 50 kueri teratas
 * per hari) dan PageSpeed (halaman utama). Idempoten: tiap baris di-upsert pada kunci uniknya, jadi menarik ulang hari yang
 * sama tidak menggandakan data. Satu bagian yang gagal tidak menggagalkan yang lain; galatnya dicatat di status tanpa
 * kunci, jalur berkas, atau token.
 */
class SeoSyncService
{
    public const STATUS_KEY = 'seo:sync-status';

    private const BACKFILL_DAYS = 90;

    /** Hari terakhir ditarik ulang karena angka final bisa bergeser sedikit. */
    private const REFRESH_DAYS = 6;

    private const TOP_ROWS = 50;

    private const KEEP_MONTHS = 16;

    /** Sinkron yang macet dianggap selesai setelah ini. */
    private const RUNNING_MINUTES = 10;

    public function __construct(
        private GoogleAccessToken $google,
        private SearchConsoleClient $console,
        private PageSpeedClient $speed,
        private SeoHealthService $seo,
    ) {}

    /** @return array{search_console: bool, pagespeed: bool} */
    public function configured(): array
    {
        return ['search_console' => $this->google->configured(), 'pagespeed' => $this->speed->configured()];
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return Cache::get(self::STATUS_KEY) ?? ['state' => 'never', 'message' => 'Belum pernah disinkronkan.'];
    }

    /**
     * Langkah checklist yang dibuktikan data Google: properti terbaca, sitemap terdaftar, PageSpeed pernah jalan.
     *
     * @return array<string, bool>
     */
    public function confirmations(): array
    {
        $status = $this->status();

        return [
            'search-console' => ($status['property_ok'] ?? null) === true,
            'sitemap-submitted' => ($status['sitemap_found'] ?? null) === true,
            'pagespeed' => DB::table('seo_pagespeed_runs')->exists(),
        ];
    }

    public function isRunning(): bool
    {
        return ($this->status()['state'] ?? null) === 'running';
    }

    /** Tandai antre atau berjalan sebelum pekerjaan dimulai, supaya tombol tidak ditekan dua kali. */
    public function markQueued(): void
    {
        Cache::put(self::STATUS_KEY, [...$this->status(), 'state' => 'running', 'message' => 'Sinkron berjalan...', 'started_at' => now()->toIso8601String()], now()->addMinutes(self::RUNNING_MINUTES));
    }

    /** @return array<string, mixed> status akhir */
    public function sync(): array
    {
        $configured = $this->configured();
        if (! $configured['search_console'] && ! $configured['pagespeed']) {
            return $this->finish('not_configured', 'Kunci Google belum diisi di server.', null, null);
        }

        $this->markQueued();
        $errors = [];
        $propertyOk = null;
        $sitemapFound = null;

        if ($configured['search_console']) {
            try {
                $this->pullSearch();
                $propertyOk = true;
            } catch (Throwable $e) {
                $propertyOk = false;
                $errors[] = 'Search Console: '.$this->safe($e);
            }

            if ($propertyOk) {
                try {
                    $sitemapFound = collect($this->console->sitemaps())->contains(fn (array $sitemap) => str_ends_with((string) ($sitemap['path'] ?? ''), '/sitemap.xml'));
                } catch (Throwable $e) {
                    $errors[] = 'Daftar sitemap: '.$this->safe($e);
                }
            }
        }

        if ($configured['pagespeed']) {
            foreach ($this->pageSpeedUrls() as $url) {
                try {
                    DB::table('seo_pagespeed_runs')->insert([...$this->speed->run($url), 'url' => $url, 'strategy' => 'mobile', 'ran_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                } catch (Throwable $e) {
                    $errors[] = 'PageSpeed '.parse_url($url, PHP_URL_PATH).': '.$this->safe($e);
                }
            }
        }

        $this->prune();

        return $this->finish($errors ? 'error' : 'ok', $errors ? implode(' ', $errors) : 'Sinkron selesai.', $propertyOk, $sitemapFound);
    }

    /** Hapus data lebih lama dari 16 bulan. */
    public function prune(): void
    {
        $cutoff = now()->subMonths(self::KEEP_MONTHS);
        foreach (['seo_search_daily', 'seo_search_pages', 'seo_search_queries'] as $table) {
            DB::table($table)->where('date', '<', $cutoff->toDateString())->delete();
        }
        DB::table('seo_pagespeed_runs')->where('ran_at', '<', $cutoff)->delete();
    }

    /** Total harian diambil terpisah (hanya dimensi tanggal): Google memperingatkan data bisa terpotong bila halaman atau kueri ikut. */
    private function pullSearch(): void
    {
        $end = CarbonImmutable::now('America/Los_Angeles')->subDays(2)->startOfDay();
        $last = DB::table('seo_search_daily')->max('date');
        $start = $last ? CarbonImmutable::parse($last)->subDays(self::REFRESH_DAYS) : $end->subDays(self::BACKFILL_DAYS - 1);
        if ($start->greaterThan($end)) {
            $start = $end;
        }
        [$from, $to] = [$start->toDateString(), $end->toDateString()];
        $now = now();

        $daily = collect($this->console->query($from, $to, ['date']))->map(fn (array $row) => [
            'date' => $row['keys'][0],
            'clicks' => (int) $row['clicks'],
            'impressions' => (int) $row['impressions'],
            'ctr' => round((float) $row['ctr'], 6),
            'position' => round((float) $row['position'], 3),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        foreach (array_chunk($daily, 500) as $chunk) {
            DB::table('seo_search_daily')->upsert($chunk, ['date'], ['clicks', 'impressions', 'ctr', 'position', 'updated_at']);
        }

        $this->storeTop('seo_search_pages', 'page', $this->console->query($from, $to, ['date', 'page']));
        $this->storeTop('seo_search_queries', 'query', $this->console->query($from, $to, ['date', 'query']));
    }

    /**
     * Simpan 50 baris teratas (klik, lalu tayangan) untuk tiap tanggal.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeTop(string $table, string $column, array $rows): void
    {
        $now = now();
        $top = collect($rows)->groupBy(fn (array $row) => $row['keys'][0])->flatMap(
            fn ($day) => $day->sortBy([['clicks', 'desc'], ['impressions', 'desc']])->take(self::TOP_ROWS)->map(fn (array $row) => [
                'date' => $row['keys'][0],
                $column => Str::limit((string) $row['keys'][1], 255, ''),
                'clicks' => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
                'ctr' => round((float) $row['ctr'], 6),
                'position' => round((float) $row['position'], 3),
                'created_at' => $now,
                'updated_at' => $now,
            ])
        )->unique(fn (array $row) => $row['date'].'|'.$row[$column])->values()->all();

        foreach (array_chunk($top, 500) as $chunk) {
            DB::table($table)->upsert($chunk, ['date', $column], ['clicks', 'impressions', 'ctr', 'position', 'updated_at']);
        }
    }

    /**
     * Halaman yang diukur: beranda, katalog, satu halaman kategori, satu produk. Hanya dari daftar halaman buatan server.
     *
     * @return list<string>
     */
    private function pageSpeedUrls(): array
    {
        $pages = $this->seo->pages();
        $base = rtrim((string) config('app.url'), '/');

        return collect(['/', '/produk'])
            ->concat([$pages->firstWhere('type', 'category')['path'] ?? null, $pages->firstWhere('type', 'product')['path'] ?? null])
            ->filter()->map(fn (string $path) => $base.$path)->unique()->values()->all();
    }

    /** @return array<string, mixed> */
    private function finish(string $state, string $message, ?bool $propertyOk, ?bool $sitemapFound): array
    {
        $previous = $this->status();
        $status = [
            'state' => $state,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
            // Hasil terakhir yang diketahui dipertahankan bila kali ini tidak diperiksa (mis. hanya PageSpeed yang jalan).
            'property_ok' => $propertyOk ?? ($previous['property_ok'] ?? null),
            'sitemap_found' => $sitemapFound ?? ($previous['sitemap_found'] ?? null),
        ];
        Cache::forever(self::STATUS_KEY, $status);

        return $status;
    }

    /** Pesan galat yang aman ditampilkan: tanpa kunci API, jalur berkas, atau isi respons panjang. */
    private function safe(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $detail = Arr::get((array) $e->response->json(), 'error.message') ?: $e->response->reason();

            return 'Google menjawab '.$e->response->status().': '.Str::limit((string) $detail, 140);
        }

        $secrets = array_filter([config('services.google_seo.credentials_path'), config('services.google_seo.pagespeed_key')]);

        return Str::limit(str_replace($secrets, '[disembunyikan]', $e->getMessage()), 160);
    }
}
