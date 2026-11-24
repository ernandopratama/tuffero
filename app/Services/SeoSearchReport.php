<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Search Console dan PageSpeed untuk dashboard, dari tabel yang diisi SeoSyncService. Rentang berakhir di tanggal data
 * terakhir (bukan hari ini, karena Google tertinggal 2 sampai 3 hari) dan dibandingkan dengan rentang sama panjang sebelumnya.
 * Daftar halaman dan kueri adalah gabungan 50 teratas per hari, bukan semua baris.
 */
class SeoSearchReport
{
    private const PERIODS = [7, 28, 90];

    private const LIST_LIMIT = 20;

    /** @return array<string, mixed> */
    public function build(int $days): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 28;
        $last = DB::table('seo_search_daily')->max('date');

        return [
            'days' => $days,
            'has_data' => $last !== null,
            'speed' => $this->speed(),
            ...($last === null ? ['range' => null, 'totals' => null, 'previous' => null, 'daily' => [], 'pages' => [], 'queries' => []] : $this->search($days, CarbonImmutable::parse($last))),
        ];
    }

    /** @return array<string, mixed> */
    private function search(int $days, CarbonImmutable $to): array
    {
        $from = $to->subDays($days - 1);
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($from, $to),
            'previous' => $this->totals($previousFrom, $previousTo),
            'daily' => DB::table('seo_search_daily')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')
                ->get(['date', 'clicks', 'impressions'])->map(fn ($row) => ['date' => $row->date, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions])->all(),
            'pages' => $this->top('seo_search_pages', 'page', $from, $to),
            'queries' => $this->top('seo_search_queries', 'query', $from, $to),
        ];
    }

    /** @return array{clicks: int, impressions: int, ctr: float, position: float}|null */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $row = DB::table('seo_search_daily')->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) as days, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as weighted')->first();

        return $row && $row->days > 0 ? [
            'clicks' => (int) $row->clicks,
            'impressions' => (int) $row->impressions,
            'ctr' => $row->impressions > 0 ? round($row->clicks / $row->impressions, 4) : 0.0,
            'position' => $row->impressions > 0 ? round($row->weighted / $row->impressions, 1) : 0.0,
        ] : null;
    }

    /** @return list<array{key: string, clicks: int, impressions: int, ctr: float, position: float}> */
    private function top(string $table, string $column, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table($table)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("$column as label, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as weighted")
            ->groupBy($column)->orderByDesc('clicks')->orderByDesc('impressions')->limit(self::LIST_LIMIT)->get()
            ->map(fn ($row) => [
                'key' => (string) $row->label,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'ctr' => $row->impressions > 0 ? round($row->clicks / $row->impressions, 4) : 0.0,
                'position' => $row->impressions > 0 ? round($row->weighted / $row->impressions, 1) : 0.0,
            ])->all();
    }

    /**
     * Hasil PageSpeed terbaru per alamat, dengan hasil sebelumnya untuk tren.
     *
     * @return list<array<string, mixed>>
     */
    private function speed(): array
    {
        $latestIds = DB::table('seo_pagespeed_runs')->selectRaw('MAX(id) as id')->groupBy('url')->pluck('id');

        return DB::table('seo_pagespeed_runs')->whereIn('id', $latestIds)->orderBy('url')->get()->map(function ($run) {
            $previous = DB::table('seo_pagespeed_runs')->where('url', $run->url)->where('id', '<', $run->id)->orderByDesc('id')->first();

            return [
                'url' => $run->url,
                'path' => parse_url($run->url, PHP_URL_PATH) ?: '/',
                'ran_at' => CarbonImmutable::parse($run->ran_at)->toIso8601String(),
                'score' => $run->score,
                'lcp_ms' => $run->lcp_ms,
                'cls' => $run->cls !== null ? (float) $run->cls : null,
                'tbt_ms' => $run->tbt_ms,
                'field_lcp_ms' => $run->field_lcp_ms,
                'field_inp_ms' => $run->field_inp_ms,
                'field_cls' => $run->field_cls !== null ? (float) $run->field_cls : null,
                'field_category' => $run->field_category,
                'field_scope' => $run->field_scope,
                'previous_score' => $previous?->score,
            ];
        })->all();
    }
}
