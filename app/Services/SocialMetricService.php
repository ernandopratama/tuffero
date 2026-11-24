<?php

namespace App\Services;

use App\Models\AccountAudienceSnapshot;
use App\Models\AuditLog;
use App\Models\SocialAccountMetric;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Performa akun media sosial (D-151 tahap 1): catatan manual per platform dan periode, ringkasan pertumbuhan followers,
 * tren harian, mingguan, atau bulanan, rangkuman aturan tetap, dan snapshot demografi bulanan. Tidak memanggil API
 * Instagram atau TikTok. Catatan tes (`is_test`) tidak ikut hitungan kecuali diminta.
 */
class SocialMetricService
{
    /** Akun dianggap belum diperbarui bila catatan terakhirnya lebih lama dari ini (hari). */
    public const STALE_DAYS = 2;

    /** Perubahan views antar dua separuh rentang yang dianggap berarti (persen). */
    private const VIEWS_SHIFT_PERCENT = 15;

    /** Angka yang boleh dijumlahkan antar catatan. Angka unik (reach, engaged, unique viewers) tidak. */
    private const ADDITIVE = ['views', 'profile_visits', 'link_taps', 'interactions', 'likes', 'comments', 'shares'];

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): SocialAccountMetric
    {
        return DB::transaction(function () use ($data, $actor) {
            $metric = new SocialAccountMetric($data);
            $metric->source = 'manual';
            $metric->recorded_by_user_id = $actor->id;
            $this->saveOrDuplicate($metric);
            AuditLog::record('social_metric.added', $metric, ['platform' => $metric->platform, 'period_days' => $metric->period_days, 'period_end' => $metric->period_end, 'is_test' => $metric->is_test], $actor->id);

            return $metric;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SocialAccountMetric $metric, array $data, User $actor): SocialAccountMetric
    {
        return DB::transaction(function () use ($metric, $data, $actor) {
            // Kolom milik platform lain tidak boleh tertinggal bila platform diganti.
            $this->saveOrDuplicate($metric->forceFill(array_fill_keys(SocialAccountMetric::allFields(), null))->fill($data));
            AuditLog::record('social_metric.updated', $metric, ['platform' => $metric->platform, 'period_days' => $metric->period_days, 'period_end' => $metric->period_end, 'is_test' => $metric->is_test], $actor->id);

            return $metric;
        });
    }

    public function delete(SocialAccountMetric $metric, User $actor): void
    {
        DB::transaction(function () use ($metric, $actor) {
            AuditLog::record('social_metric.deleted', $metric, ['platform' => $metric->platform, 'period_days' => $metric->period_days, 'period_end' => $metric->period_end, 'is_test' => $metric->is_test], $actor->id);
            $metric->delete();
        });
    }

    /** Hapus semua catatan dan snapshot bertanda tes sekaligus; mengembalikan jumlah yang dihapus. */
    public function deleteTestData(User $actor): int
    {
        return DB::transaction(function () use ($actor) {
            $metrics = SocialAccountMetric::query()->where('is_test', true)->get();
            $snapshots = AccountAudienceSnapshot::query()->where('is_test', true)->get();
            $count = $metrics->count() + $snapshots->count();
            if ($count === 0) {
                return 0;
            }
            // Satu baris audit per jenis (kunci target: catatan pertama), bukan satu per catatan.
            $first = $metrics->first() ?? $snapshots->first();
            if ($first) {
                AuditLog::record('social_metric.test_data_deleted', $first, ['metrics' => $metrics->count(), 'snapshots' => $snapshots->count()], $actor->id);
            }
            SocialAccountMetric::query()->where('is_test', true)->delete();
            AccountAudienceSnapshot::query()->where('is_test', true)->delete();

            return $count;
        });
    }

    /**
     * Simpan demografi satu bulan; catatan platform dan bulan yang sama diganti.
     *
     * @param  array<string, mixed>  $data  platform, month (Y-m-01), is_test, data
     */
    public function saveAudience(array $data, User $actor): AccountAudienceSnapshot
    {
        return DB::transaction(function () use ($data, $actor) {
            $snapshot = AccountAudienceSnapshot::query()->firstOrNew([
                'platform' => $data['platform'], 'month' => $data['month'], 'is_test' => (bool) $data['is_test'],
            ]);
            $snapshot->data = $data['data'];
            $snapshot->recorded_by_user_id = $actor->id;
            $snapshot->save();
            AuditLog::record('social_audience.saved', $snapshot, ['platform' => $snapshot->platform, 'month' => $snapshot->month, 'is_test' => $snapshot->is_test], $actor->id);

            return $snapshot;
        });
    }

    public function deleteAudience(AccountAudienceSnapshot $snapshot, User $actor): void
    {
        DB::transaction(function () use ($snapshot, $actor) {
            AuditLog::record('social_audience.deleted', $snapshot, ['platform' => $snapshot->platform, 'month' => $snapshot->month, 'is_test' => $snapshot->is_test], $actor->id);
            $snapshot->delete();
        });
    }

    /**
     * Ringkasan untuk halaman Performa Akun dan kartu Ringkasan.
     *
     * @param  int  $days  rentang (hari terakhir)
     * @param  int  $period  jenis catatan yang dibaca untuk tren views dan interaksi: 1, 7, atau 30 hari
     * @return array<string, mixed>
     */
    public function overview(int $days, int $period, ?string $platform, bool $includeTest): array
    {
        // Tidak boleh berubah: dipakai berkali-kali untuk batas hari.
        $today = now('Asia/Jakarta')->startOfDay()->toImmutable();
        $since = $today->subDays($days)->toDateString();
        $names = $platform ? [$platform] : array_keys(SocialAccountMetric::PLATFORMS);

        $query = SocialAccountMetric::query()->whereIn('platform', $names)->when(! $includeTest, fn ($q) => $q->where('is_test', false));
        $inRange = (clone $query)->where('period_end', '>=', $since)->orderBy('period_end')->orderBy('id')->get();
        // Titik awal followers: catatan terakhir sebelum rentang, supaya pertumbuhan terhitung walau catatan pertama baru masuk di tengah rentang.
        $before = [];
        foreach ($names as $name) {
            $before[$name] = (clone $query)->where('platform', $name)->where('period_end', '<', $since)->orderByDesc('period_end')->orderByDesc('id')->first();
        }

        $platforms = [];
        $trend = array_map(fn (int $back) => ['date' => $today->subDays($back)->toDateString(), 'followers' => [], 'views' => [], 'interactions' => []], range($days, 0));
        foreach ($names as $name) {
            $rows = $inRange->where('platform', $name)->values();
            $series = $this->series($rows->all(), $before[$name], $period, $trend);
            $trend = $series['trend'];
            $platforms[$name] = $this->platformSummary($name, $rows->all(), $before[$name], $period, $today, $includeTest);
        }

        $insights = $this->insights($platforms, $days, $period);

        $tests = SocialAccountMetric::query()->where('is_test', true)->count() + AccountAudienceSnapshot::query()->where('is_test', true)->count();
        $records = $inRange->where('period_days', $period)->sortByDesc(fn (SocialAccountMetric $m) => $m->period_end.sprintf('%012d', $m->id))->take(60)->values();

        return [
            'days' => $days,
            'period' => $period,
            'include_test' => $includeTest,
            'test_count' => $tests,
            'platforms' => $platforms,
            'trend' => $trend,
            'insights' => $insights,
            'records' => $records->map(fn (SocialAccountMetric $m) => $this->present($m))->all(),
            'audience' => $this->audience($names, $includeTest),
            'last_recorded_on' => $inRange->max('period_end'),
        ];
    }

    /** @return array<string, mixed> */
    public function present(SocialAccountMetric $metric): array
    {
        return [
            'id' => $metric->public_id,
            'platform' => $metric->platform,
            'period_days' => $metric->period_days,
            'period_end' => $metric->period_end,
            'measured_on' => $metric->measured_on,
            'is_test' => $metric->is_test,
            'source' => $metric->source,
            'note' => $metric->note,
            ...$metric->only(SocialAccountMetric::fieldsFor($metric->platform)),
            'recorder' => $metric->recorder?->name,
        ];
    }

    /**
     * Titik tren per hari. Followers dibawa maju dari catatan terakhir; views dan interaksi hanya diisi pada hari
     * berakhirnya catatan berperiode `$period` (hari lain kosong, grafik menyambung titik yang ada).
     *
     * @param  list<SocialAccountMetric>  $rows
     * @param  list<array<string, mixed>>  $trend
     * @return array{trend: list<array<string, mixed>>}
     */
    private function series(array $rows, ?SocialAccountMetric $before, int $period, array $trend): array
    {
        $platform = $rows[0]->platform ?? $before?->platform;
        if ($platform === null) {
            return ['trend' => $trend];
        }
        $followers = $before?->followers_total;
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->period_end][] = $row;
        }
        foreach ($trend as $i => $point) {
            foreach ($byDate[$point['date']] ?? [] as $row) {
                $followers = $row->followers_total;
                if ($row->period_days === $period) {
                    $trend[$i]['views'][$platform] = $row->views;
                    $trend[$i]['interactions'][$platform] = self::interactions($row);
                }
            }
            $trend[$i]['followers'][$platform] = $followers;
        }

        return ['trend' => $trend];
    }

    /** Instagram: interactions. TikTok: likes + comments + shares (kosong bila ketiganya kosong). */
    private static function interactions(SocialAccountMetric $row): ?int
    {
        if ($row->platform === 'instagram') {
            return $row->interactions;
        }
        $parts = array_filter([$row->likes, $row->comments, $row->shares], fn ($v) => $v !== null);

        return $parts === [] ? null : array_sum($parts);
    }

    /**
     * @param  list<SocialAccountMetric>  $rows
     * @return array<string, mixed>
     */
    private function platformSummary(string $platform, array $rows, ?SocialAccountMetric $before, int $period, CarbonImmutable $today, bool $includeTest): array
    {
        $last = $rows === [] ? null : $rows[count($rows) - 1];
        $first = $before ?? ($rows[0] ?? null);
        $current = $last?->followers_total;
        $change = $pct = null;
        if ($last !== null) {
            $base = $before ?? $rows[0];
            if ($last->id !== $base->id) {
                $change = $last->followers_total - $base->followers_total;
                $pct = $base->followers_total > 0 ? round($change / $base->followers_total * 100, 2) : null;
            }
        }

        $mine = array_values(array_filter($rows, fn (SocialAccountMetric $m) => $m->period_days === $period));
        $latest = $mine === [] ? null : $mine[count($mine) - 1];
        $totals = [];
        foreach (SocialAccountMetric::fieldsFor($platform) as $field) {
            if (! in_array($field, self::ADDITIVE, true)) {
                continue;
            }
            $values = array_filter(array_map(fn (SocialAccountMetric $m) => $m->{$field}, $mine), fn ($v) => $v !== null);
            $totals[$field] = $values === [] ? null : array_sum($values);
        }

        // "Belum diperbarui" memakai catatan terakhir apa pun (nyata atau tes sesuai pilihan), bukan hanya yang dalam rentang.
        $newest = SocialAccountMetric::query()->where('platform', $platform)->when(! $includeTest, fn ($q) => $q->where('is_test', false))->max('period_end');
        $since = $newest ? (int) round(($today->timestamp - strtotime($newest.' Asia/Jakarta')) / 86400) : null;

        return [
            'platform' => $platform,
            'records' => count($mine),
            'followers' => ['current' => $current, 'change' => $change, 'change_percent' => $pct, 'since' => $first?->period_end],
            'latest' => $latest ? $this->present($latest) : null,
            'totals' => $totals,
            'views_shift' => self::viewsShift(array_map(fn (SocialAccountMetric $m) => $m->views, $mine)),
            'last_recorded_on' => $newest,
            'days_since' => $since,
            'stale' => $since === null || $since > self::STALE_DAYS,
        ];
    }

    /**
     * Rangkuman dari aturan tetap (bukan AI). Selalu hipotesis: sampel kecil, jadi bukan kesimpulan.
     *
     * @param  array<string, array<string, mixed>>  $platforms
     * @return list<array{type: string, tone: string, platform: string|null, text: string}>
     */
    private function insights(array $platforms, int $days, int $period): array
    {
        $out = [];
        $label = SocialAccountMetric::PLATFORMS;
        foreach ($platforms as $name => $summary) {
            $followers = $summary['followers'];
            if ($followers['change'] !== null) {
                $change = $followers['change'];
                $pct = $followers['change_percent'] !== null ? ' ('.$this->percent(abs($followers['change_percent'])).')' : '';
                $out[] = $change === 0
                    ? ['type' => 'growth', 'tone' => 'neutral', 'platform' => $name, 'text' => "Followers {$label[$name]} tidak berubah dalam {$days} hari terakhir."]
                    : ['type' => 'growth', 'tone' => $change > 0 ? 'good' : 'warn', 'platform' => $name, 'text' => "Followers {$label[$name]} ".($change > 0 ? 'naik ' : 'turun ').$this->number(abs($change))."{$pct} dalam {$days} hari terakhir."];
            }
            $shift = $summary['views_shift'];
            if ($shift !== null && abs($shift) >= self::VIEWS_SHIFT_PERCENT) {
                $out[] = ['type' => 'views', 'tone' => $shift > 0 ? 'good' : 'warn', 'platform' => $name, 'text' => "Views {$label[$name]} separuh rentang terakhir ".($shift > 0 ? 'naik ' : 'turun ').$this->percent(abs($shift)).' dibanding separuh sebelumnya.'];
            }
            if ($summary['stale']) {
                $out[] = ['type' => 'reminder', 'tone' => 'warn', 'platform' => $name, 'text' => $summary['last_recorded_on']
                    ? "Akun {$label[$name]} belum dicatat sejak {$summary['last_recorded_on']}. Salin angka terbaru dari insight."
                    : "Akun {$label[$name]} belum punya catatan. Isi catatan pertama dari insight."];
            }
            $taps = $summary['totals']['link_taps'] ?? null;
            $visits = $summary['totals']['profile_visits'] ?? null;
            if ($name === 'instagram' && $taps !== null && $visits) {
                $out[] = ['type' => 'conversion', 'tone' => 'neutral', 'platform' => $name, 'text' => 'Dari 100 kunjungan profil Instagram, '.$this->number(round($taps / $visits * 100, 1)).' menekan tautan.'];
            }
        }

        if (count($platforms) === 2) {
            $pcts = [];
            foreach ($platforms as $name => $summary) {
                if ($summary['followers']['change_percent'] !== null) {
                    $pcts[$name] = $summary['followers']['change_percent'];
                }
            }
            if (count($pcts) === 2) {
                arsort($pcts);
                $names = array_keys($pcts);
                if ($pcts[$names[0]] - $pcts[$names[1]] >= 0.5) {
                    $out[] = ['type' => 'compare', 'tone' => 'neutral', 'platform' => null, 'text' => "Followers {$label[$names[0]]} tumbuh lebih cepat ({$this->percent($pcts[$names[0]])}) dibanding {$label[$names[1]]} ({$this->percent($pcts[$names[1]])}) dalam {$days} hari terakhir."];
                }
            }
        }

        if ($out === []) {
            $out[] = ['type' => 'empty', 'tone' => 'neutral', 'platform' => null, 'text' => 'Belum cukup catatan untuk rangkuman. Isi beberapa hari dulu.'];
        }

        return $out;
    }

    /**
     * Pergeseran views antar dua separuh rentang untuk satu platform; dipanggil dari `overview` lewat catatan berperiode.
     * Butuh minimal 4 catatan. Dipisah supaya bisa diuji sendiri.
     *
     * @param  list<int|null>  $views  urut dari yang terlama
     */
    public static function viewsShift(array $views): ?float
    {
        $values = array_values(array_filter($views, fn ($v) => $v !== null));
        if (count($values) < 4) {
            return null;
        }
        $half = intdiv(count($values), 2);
        $older = array_sum(array_slice($values, 0, $half));
        $newer = array_sum(array_slice($values, -$half));

        return $older > 0 ? round(($newer - $older) / $older * 100, 1) : null;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>|null>
     */
    private function audience(array $names, bool $includeTest): array
    {
        $out = [];
        foreach ($names as $name) {
            $snapshot = AccountAudienceSnapshot::query()->where('platform', $name)->when(! $includeTest, fn ($q) => $q->where('is_test', false))->orderByDesc('month')->first();
            $out[$name] = $snapshot ? ['id' => $snapshot->public_id, 'month' => $snapshot->month, 'is_test' => $snapshot->is_test, 'data' => $snapshot->data] : null;
        }

        return $out;
    }

    private function number(int|float $value): string
    {
        return number_format($value, is_float($value) && floor($value) !== $value ? 1 : 0, ',', '.');
    }

    private function percent(float $value): string
    {
        return number_format($value, 2, ',', '.').'%';
    }

    /** Dua simpan bersamaan lolos validasi unik; indeks unik yang menolak yang kedua, dijawab sebagai galat validasi biasa. */
    private function saveOrDuplicate(SocialAccountMetric $metric): void
    {
        try {
            $metric->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['period_end' => 'Catatan untuk platform, periode, dan tanggal ini sudah ada. Ubah catatan itu saja.']);
        }
    }
}
