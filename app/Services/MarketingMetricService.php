<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MarketingDraft;
use App\Models\MarketingDraftMetric;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Analisis konten pribadi (rapat 14 Nov 2026 poin 28): catatan metrik harian yang diisi manual untuk draf yang sudah
 * diposting, ringkasan per konten, dan peringkat konten per platform. Tidak memanggil API TikTok atau Instagram.
 */
class MarketingMetricService
{
    private const TOP_LIMIT = 5;

    /** @param array<string, mixed> $data */
    public function create(MarketingDraft $draft, array $data, User $actor): MarketingDraftMetric
    {
        $this->ensurePosted($draft);

        return DB::transaction(function () use ($draft, $data, $actor) {
            $metric = new MarketingDraftMetric($data);
            $metric->marketing_draft_id = $draft->id;
            $metric->recorded_by_user_id = $actor->id;
            $this->saveOrDuplicate($metric);
            AuditLog::record('marketing_draft.metric_added', $draft, ['metric_id' => $metric->id, 'platform' => $metric->platform, 'measured_on' => $metric->measured_on], $actor->id);

            return $metric;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(MarketingDraft $draft, MarketingDraftMetric $metric, array $data, User $actor): MarketingDraftMetric
    {
        $this->ensurePosted($draft);

        return DB::transaction(function () use ($draft, $metric, $data, $actor) {
            $this->saveOrDuplicate($metric->fill($data));
            AuditLog::record('marketing_draft.metric_updated', $draft, ['metric_id' => $metric->id, 'platform' => $metric->platform, 'measured_on' => $metric->measured_on], $actor->id);

            return $metric;
        });
    }

    public function delete(MarketingDraft $draft, MarketingDraftMetric $metric, User $actor): void
    {
        DB::transaction(function () use ($draft, $metric, $actor) {
            AuditLog::record('marketing_draft.metric_deleted', $draft, ['metric_id' => $metric->id, 'platform' => $metric->platform, 'measured_on' => $metric->measured_on], $actor->id);
            $metric->delete();
        });
    }

    /**
     * Ringkasan per platform: angka terakhir (kumulatif), selisih terhadap catatan sebelumnya, dan rasio interaksi.
     *
     * @return array<string, array<string, mixed>>
     */
    public function summary(MarketingDraft $draft): array
    {
        return $draft->metrics()->orderBy('measured_on')->get()
            ->groupBy('platform')
            ->map(function (Collection $rows) {
                /** @var MarketingDraftMetric $latest */
                $latest = $rows->last();
                $previous = $rows->count() > 1 ? $rows[$rows->count() - 2] : null;
                $fields = [...MarketingDraftMetric::COUNTS, ...MarketingDraftMetric::OPTIONAL_COUNTS];

                return [
                    'platform' => $latest->platform,
                    'post_url' => $latest->post_url,
                    'days' => $rows->count(),
                    'first_on' => $rows->first()->measured_on,
                    'latest_on' => $latest->measured_on,
                    'totals' => $latest->only($fields),
                    'change' => $previous ? collect($fields)->mapWithKeys(fn ($field) => [$field => $latest->{$field} === null || $previous->{$field} === null ? null : $latest->{$field} - $previous->{$field}])->all() : null,
                    'engagement_rate' => self::engagementRate($latest),
                ];
            })
            ->all();
    }

    /**
     * Konten teratas per platform menurut views pada catatan terakhir tiap draf. Draf yang dihapus tidak ikut.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function top(): array
    {
        $latest = MarketingDraftMetric::query()
            ->selectRaw('marketing_draft_id, platform, max(measured_on) as last_on')
            ->groupBy('marketing_draft_id', 'platform');

        $rows = MarketingDraftMetric::query()
            ->select('marketing_draft_metrics.*')
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('marketing_draft_metrics.marketing_draft_id', '=', 'latest.marketing_draft_id')
                    ->on('marketing_draft_metrics.platform', '=', 'latest.platform')
                    ->on('marketing_draft_metrics.measured_on', '=', 'latest.last_on');
            })
            ->whereHas('draft')
            ->with('draft:id,public_id,number,title')
            ->orderByDesc('views')
            ->orderByDesc('marketing_draft_metrics.id')
            ->get();

        return collect(array_keys(MarketingDraftMetric::PLATFORMS))
            ->mapWithKeys(fn (string $platform) => [$platform => $rows->where('platform', $platform)->take(self::TOP_LIMIT)->map(fn (MarketingDraftMetric $metric) => [
                'draft_id' => $metric->draft->public_id,
                'number' => $metric->draft->number,
                'title' => $metric->draft->title,
                'post_url' => $metric->post_url,
                'measured_on' => $metric->measured_on,
                ...$metric->only(MarketingDraftMetric::COUNTS),
                'engagement_rate' => self::engagementRate($metric),
            ])->values()->all()])
            ->all();
    }

    /**
     * Baris datar untuk Hermes (hanya baca): satu baris per konten, platform, dan tanggal ukur dalam `$days` hari terakhir.
     * Tanpa tautan posting, pembuat, atau data pelanggan. Draf yang dihapus tidak ikut. Dibatasi `$limit` baris terbaru.
     *
     * @return list<array<string, mixed>>
     */
    public function export(int $days, int $limit = 2000): array
    {
        return MarketingDraftMetric::query()
            ->with('draft:id,public_id,number,title')
            ->whereHas('draft')
            ->where('measured_on', '>=', now('Asia/Jakarta')->subDays($days)->toDateString())
            ->orderByDesc('measured_on')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MarketingDraftMetric $metric) => [
                'draft_id' => $metric->draft->public_id,
                'number' => $metric->draft->number,
                'title' => $metric->draft->title,
                'platform' => $metric->platform,
                'measured_on' => $metric->measured_on,
                ...$metric->only([...MarketingDraftMetric::COUNTS, ...MarketingDraftMetric::OPTIONAL_COUNTS]),
            ])
            ->all();
    }

    /** Konten dianggap belum diperbarui bila catatan terakhirnya lebih lama dari ini (hari). */
    public const STALE_DAYS = 3;

    /** Hanya draf yang diposting dalam rentang ini yang diingatkan bila belum dicatat (hari). */
    private const REMIND_WITHIN_DAYS = 60;

    private const SPARK_POINTS = 10;

    /**
     * Ringkasan untuk kartu dashboard dan halaman Performa Konten: satu baris per konten dan platform yang punya catatan
     * dalam `$days` hari terakhir (angka terakhir, selisih dari catatan sebelumnya, titik tren views), total per platform,
     * dan konten diposting yang belum dicatat. Angka platform bersifat kumulatif, jadi total adalah jumlah angka terakhir.
     *
     * @return array<string, mixed>
     */
    public function overview(int $days, ?string $platform = null): array
    {
        // Tidak boleh berubah: dipakai berkali-kali untuk batas hari (Carbon biasa berubah saat subDays dipanggil).
        $today = now('Asia/Jakarta')->startOfDay()->toImmutable();
        $since = $today->subDays($days)->toDateString();

        $ids = MarketingDraftMetric::query()->whereHas('draft')->where('measured_on', '>=', $since)
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->select('marketing_draft_id', 'platform')->distinct()->get();

        $series = $ids->isEmpty() ? collect() : MarketingDraftMetric::query()
            ->with('draft:id,public_id,number,title')
            ->whereIn('marketing_draft_id', $ids->pluck('marketing_draft_id')->unique())
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->orderBy('measured_on')->get()->groupBy(fn (MarketingDraftMetric $m) => $m->marketing_draft_id.'|'.$m->platform);

        $items = $series->map(function (Collection $rows) use ($today) {
            /** @var MarketingDraftMetric $last */
            $last = $rows->last();
            $previous = $rows->count() > 1 ? $rows[$rows->count() - 2] : null;

            return [
                'draft_id' => $last->draft->public_id,
                'number' => $last->draft->number,
                'title' => $last->draft->title,
                'platform' => $last->platform,
                'latest_on' => $last->measured_on,
                'stale' => (int) round(($today->timestamp - strtotime($last->measured_on.' Asia/Jakarta')) / 86400) > self::STALE_DAYS,
                'records' => $rows->count(),
                ...$last->only([...MarketingDraftMetric::COUNTS, ...MarketingDraftMetric::OPTIONAL_COUNTS]),
                'engagement_rate' => self::engagementRate($last),
                'views_change' => $previous ? $last->views - $previous->views : null,
                'spark' => $rows->slice(-self::SPARK_POINTS)->pluck('views')->values()->all(),
            ];
        })->values();

        // Tingkat dibandingkan median views akun sendiri per platform; butuh minimal 3 konten supaya median berarti.
        $items = $items->groupBy('platform')->flatMap(function (Collection $group) {
            $median = $group->count() >= 3 ? $group->pluck('views')->median() : null;

            return $group->map(fn (array $item) => [...$item, 'level' => ! $median ? null : ($item['views'] >= 2 * $median ? 'outlier' : ($item['views'] < 0.5 * $median ? 'low' : 'normal'))]);
        })->sortByDesc('views')->values();

        $totals = fn (Collection $rows) => [
            'contents' => $rows->count(),
            ...collect(MarketingDraftMetric::COUNTS)->mapWithKeys(fn (string $field) => [$field => (int) $rows->sum($field)])->all(),
            'engagement_rate' => $rows->sum('views') > 0 ? round(($rows->sum('likes') + $rows->sum('comments') + $rows->sum('shares') + $rows->sum('saves')) / $rows->sum('views') * 100, 2) : null,
        ];

        // Tren harian per platform: tiap hari, jumlah angka views terakhir yang sudah tercatat sampai hari itu (dibawa maju
        // bila hari itu tidak dicatat). Konten yang baru mulai dicatat menaikkan garis pada hari catatan pertamanya.
        $platformNames = array_keys(MarketingDraftMetric::PLATFORMS);
        $trend = [];
        for ($back = $days; $back >= 0; $back--) {
            $date = $today->subDays($back)->toDateString();
            $point = ['date' => $date, ...array_fill_keys($platformNames, 0)];
            foreach ($series as $rows) {
                $known = $rows->last(fn (MarketingDraftMetric $m) => $m->measured_on <= $date);
                if ($known) {
                    $point[$known->platform] += $known->views;
                }
            }
            $trend[] = $point;
        }

        $recent = MarketingDraftMetric::query()->where('measured_on', '>=', $today->subDays(self::STALE_DAYS)->toDateString())->pluck('marketing_draft_id')->unique();
        $missing = MarketingDraft::query()
            ->where('status', MarketingDraft::POSTED)->where('type', 'video_script')
            ->where('posted_at', '>=', $today->subDays(self::REMIND_WITHIN_DAYS))
            ->whereNotIn('id', $recent)
            ->orderByDesc('posted_at')->limit(10)->get(['id', 'public_id', 'number', 'title', 'platform', 'posted_at']);

        return [
            'days' => $days,
            'totals' => $totals($items),
            'platforms' => collect(array_keys(MarketingDraftMetric::PLATFORMS))->mapWithKeys(fn (string $name) => [$name => $totals($items->where('platform', $name))])->all(),
            'items' => $items->all(),
            'trend' => $trend,
            'last_recorded_on' => $items->max('latest_on'),
            'needs_update' => $missing->map(fn (MarketingDraft $draft) => ['draft_id' => $draft->public_id, 'number' => $draft->number, 'title' => $draft->title, 'platform' => $draft->platform])->all(),
        ];
    }

    /** (likes + comments + shares + saves) / views, dalam persen dua desimal; null bila belum ada views. */
    public static function engagementRate(MarketingDraftMetric $metric): ?float
    {
        return $metric->views > 0
            ? round(($metric->likes + $metric->comments + $metric->shares + $metric->saves) / $metric->views * 100, 2)
            : null;
    }

    /** Dua simpan bersamaan lolos validasi unik; indeks unik yang menolak yang kedua, dijawab sebagai galat validasi biasa. */
    private function saveOrDuplicate(MarketingDraftMetric $metric): void
    {
        try {
            $metric->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['measured_on' => 'Catatan untuk platform dan tanggal ini sudah ada. Ubah catatan itu saja.']);
        }
    }

    /** Hanya konten yang sudah tayang yang punya metrik. */
    private function ensurePosted(MarketingDraft $draft): void
    {
        if ($draft->status !== MarketingDraft::POSTED) {
            throw ValidationException::withMessages(['draft' => 'Metrik hanya dicatat untuk draf yang sudah ditandai diposting.']);
        }
    }
}
