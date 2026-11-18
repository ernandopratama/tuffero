<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Order;
use App\Models\PromoContent;
use App\Models\Refund;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Halaman Laporan. `reports.view_summary` (Marketing) menerima hitungan saja; rupiah dan data pesanan
 * hanya dikirim bila akun memegang `reports.view` (Pemilik, Super Admin). Data pelanggan tidak pernah dikirim.
 */
class ReportService
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const TZ = 'Asia/Jakarta';

    /** Pengelompokan periode => nama untuk judul ("per hari"). */
    public const GROUPS = ['day' => 'hari', 'week' => 'minggu', 'month' => 'bulan', 'year' => 'tahun'];

    /** Rentang terpanjang sekali tampil, hari (sama dengan ekspor penjualan finance); per hari lebih pendek supaya grafik terbaca. */
    public const MAX_DAYS = 366;

    public const MAX_DAYS_DAILY = 92;

    /**
     * Periode bawaan halaman Laporan: tanggal 1 bulan ini sampai hari ini (waktu Jakarta), per hari.
     *
     * @return array{from: string, to: string, group: string}
     */
    public static function defaultPeriod(?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone(self::TZ);

        return ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString(), 'group' => 'day'];
    }

    /**
     * @param  array{from: string, to: string, group: string}  $period  tanggal Jakarta (Y-m-d, kedua ujung termasuk), sudah divalidasi controller
     * @return array<string, mixed>
     */
    public function build(User $user, array $period, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $money = $user->can('reports.view');
        $buckets = $this->buckets($period);

        return [
            'money' => $money,
            'generated_at' => $now->toIso8601String(),
            'period' => $period + ['name' => self::GROUPS[$period['group']]],
            'sales' => $this->sales($buckets, $period['group'], $money),
            // Angka keuangan: hanya `reports.view` (tanpa izin baru). Marketing tidak menerimanya.
            'returns' => $money ? $this->returns($period, $buckets) : null,
            'cancellations' => $money ? $this->cancellations($period, $buckets) : null,
            'top_products' => $this->topProducts($buckets, $money),
            'vouchers' => $this->vouchers($now),
            'campaigns' => $this->campaigns($now),
            'contents' => $this->contents(),
        ];
    }

    /**
     * @param  list<array{label: string, short: string, range: array{0: CarbonImmutable, 1: CarbonImmutable}}>  $buckets
     * @return array<string, mixed>
     */
    private function sales(array $buckets, string $group, bool $money): array
    {
        $ranges = array_column($buckets, 'range');
        $labels = array_column($buckets, 'label');
        $axis = array_column($buckets, 'short');
        $name = self::GROUPS[$group];
        $range = ['day' => 'Harian', 'week' => 'Mingguan', 'month' => 'Bulanan', 'year' => 'Tahunan'][$group];

        $series = [
            ['key' => 'orders', 'name' => 'Pesanan', 'tone' => 'accent', 'values' => array_map(fn ($r) => Order::where('payment_status', 'paid')->whereBetween('paid_at', $r)->count(), $ranges)],
            ['key' => 'units', 'name' => 'Produk terjual', 'tone' => 'primary', 'values' => array_map(fn ($r) => (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.payment_status', 'paid')->whereBetween('orders.paid_at', $r)->sum('order_items.quantity'), $ranges)],
        ];

        $chart = ['title' => "Penjualan per {$name}", 'range' => $range, 'format' => 'number', 'labels' => $labels, 'axis_labels' => $axis, 'series' => $series];

        $chartRevenue = null;
        if ($money) {
            $revenue = app(RevenueService::class);
            $chartRevenue = ['title' => "Pendapatan per {$name}", 'range' => $range, 'format' => 'rupiah', 'labels' => $labels, 'axis_labels' => $axis, 'series' => [
                // Kotor, dana dikembalikan, dan bersih memakai definisi yang sama dengan ringkasan Pemilik (RevenueService).
                ['key' => 'revenue', 'name' => 'Pendapatan kotor', 'tone' => 'primary', 'values' => array_map(fn ($r) => $revenue->gross($r), $ranges)],
                ['key' => 'refunded', 'name' => 'Dana dikembalikan', 'tone' => 'secondary', 'values' => array_map(fn ($r) => $revenue->refunded($r), $ranges)],
                ['key' => 'net', 'name' => 'Pendapatan bersih', 'tone' => 'success', 'values' => array_map(fn ($r) => $revenue->net($r), $ranges)],
                // D-126: potongan gratis ongkir yang ditanggung toko.
                ['key' => 'shipping_subsidy', 'name' => 'Subsidi ongkir', 'tone' => 'accent', 'values' => array_map(fn ($r) => $revenue->shippingSubsidy($r), $ranges)],
            ]];
        }

        return ['chart' => $chart, 'revenue_chart' => $chartRevenue];
    }

    /**
     * Potongan periode (waktu Jakarta) dari terlama ke terbaru, dengan batas UTC untuk kueri (database menyimpan UTC; Laravel memformat
     * Carbon dengan zonanya sendiri). Potongan pertama dan terakhir dipangkas ke ujung periode; minggu dimulai Senin.
     * shortcut: tiap potongan dihitung dengan kueri sendiri lewat RevenueService (paling banyak 92 hari per hari, sekitar 1.000 kueri)
     * supaya rumusnya satu dengan ringkasan Pemilik; ganti ke GROUP BY bila halaman terasa lambat di produksi.
     *
     * @param  array{from: string, to: string, group: string}  $period
     * @return list<array{label: string, short: string, range: array{0: CarbonImmutable, 1: CarbonImmutable}}>
     */
    private function buckets(array $period): array
    {
        $from = CarbonImmutable::parse($period['from'], self::TZ)->startOfDay();
        $to = CarbonImmutable::parse($period['to'], self::TZ)->endOfDay();
        $years = $from->year !== $to->year;
        $out = [];

        for ($start = $from; $start <= $to; $start = $end->startOfDay()->addDay()) {
            $end = match ($period['group']) {
                'week' => $start->endOfWeek(CarbonImmutable::SUNDAY),
                'month' => $start->endOfMonth(),
                'year' => $start->endOfYear(),
                default => $start->endOfDay(),
            };
            $end = $end->min($to);
            $out[] = ['label' => $this->label($start, $end, $period['group']), 'short' => $this->short($start, $period['group'], $years), 'range' => [$start->setTimezone('UTC'), $end->setTimezone('UTC')]];
        }

        return $out;
    }

    private function label(CarbonImmutable $start, CarbonImmutable $end, string $group): string
    {
        $day = fn (CarbonImmutable $d, bool $year = true) => $d->day.' '.self::MONTHS[$d->month - 1].($year ? ' '.$d->year : '');

        if ($group === 'week' && ! $start->isSameDay($end)) {
            $first = $start->year !== $end->year ? $day($start) : ($start->month !== $end->month ? $day($start, false) : (string) $start->day);

            return $first.'–'.$day($end);
        }

        return match ($group) {
            'day', 'week' => $day($start),
            'month' => self::MONTHS[$start->month - 1].' '.$start->year,
            default => (string) $start->year,
        };
    }

    /** Label pendek sumbu grafik; label lengkap tetap di tooltip, tabel, dan berkas. */
    private function short(CarbonImmutable $start, string $group, bool $years): string
    {
        return match ($group) {
            'day', 'week' => $start->day.' '.self::MONTHS[$start->month - 1],
            'month' => self::MONTHS[$start->month - 1].($years ? ' '.$start->format('y') : ''),
            default => (string) $start->year,
        };
    }

    /**
     * Rekap retur per potongan periode, terbaru di atas (D-130). Pengajuan dihitung di periode diajukan; dana dikembalikan di periode
     * `refunded_at`, rumus yang sama dengan RevenueService. Retur = produk utuh (`return_unopened`); klaim = barang bermasalah dan alasan lain.
     * Juga dipakai ekspor xlsx (ReportController::export), jadi angka berkas sama dengan layar.
     *
     * @param  array{from: string, to: string, group: string}  $period
     * @param  list<array{label: string, short: string, range: array{0: CarbonImmutable, 1: CarbonImmutable}}>|null  $buckets
     * @return list<array<string, int|string>>
     */
    public function returns(array $period, ?array $buckets = null): array
    {
        $revenue = app(RevenueService::class);

        return array_map(function (array $m) use ($revenue) {
            $r = Refund::whereBetween('created_at', $m['range'])
                ->selectRaw('COUNT(*) AS submitted')
                ->selectRaw('COALESCE(SUM(CASE WHEN reason_type = ? THEN 1 ELSE 0 END), 0) AS returns', [Refund::RETURN_REASON])
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS rejected', [Refund::REJECTED])
                ->selectRaw('COALESCE(SUM(CASE WHEN customer_choice = ? THEN 1 ELSE 0 END), 0) AS resend', ['resend'])
                ->selectRaw('COALESCE(SUM(CASE WHEN customer_choice = ? THEN 1 ELSE 0 END), 0) AS voucher', ['voucher'])
                ->toBase()->first();
            $paid = $revenue->claimRefunds($m['range']);

            return [
                'label' => $m['label'], 'submitted' => (int) $r->submitted, 'returns' => (int) $r->returns, 'claims' => (int) $r->submitted - (int) $r->returns,
                'rejected' => (int) $r->rejected, 'resend' => (int) $r->resend, 'voucher' => (int) $r->voucher,
                'refunded_count' => $paid['return_count'] + $paid['claim_count'], 'refunded_return' => $paid['return'], 'refunded_claim' => $paid['claim'], 'refunded_total' => $paid['return'] + $paid['claim'],
            ];
        }, array_reverse($buckets ?? $this->buckets($period)));
    }

    /**
     * Pesanan batal sebagai data tersendiri (D-131): dihitung di bulan pembatalan (`cancelled_at`), tidak dicampur ke angka penjualan
     * lain. Pesanan batal tidak masuk "Pendapatan kotor" (RevenueService::gross) dan dananya tidak ikut "Dana dikembalikan"; kolom
     * `refunded` memakai tanggal `refunded_at`, jadi bisa berbeda bulan dari pembatalannya.
     *
     * Per potongan periode, terbaru di atas; `pending` (menunggu dana) tidak terikat periode. Juga dipakai ekspor xlsx
     * (ReportController::export), jadi angka berkas sama dengan layar.
     *
     * @param  array{from: string, to: string, group: string}  $period
     * @param  list<array{label: string, short: string, range: array{0: CarbonImmutable, 1: CarbonImmutable}}>|null  $buckets
     * @return array{rows: list<array<string, int|string>>, pending: array{count: int, value: int}}
     */
    public function cancellations(array $period, ?array $buckets = null): array
    {
        $revenue = app(RevenueService::class);

        $rows = array_map(function (array $m) use ($revenue) {
            $r = Order::whereBetween('cancelled_at', $m['range'])
                ->selectRaw('COUNT(*) AS cancelled')
                ->selectRaw('COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS paid_n')
                ->selectRaw('COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN total ELSE 0 END), 0) AS paid_value')
                ->toBase()->first();

            return [
                'label' => $m['label'], 'cancelled' => (int) $r->cancelled, 'unpaid' => (int) $r->cancelled - (int) $r->paid_n,
                'paid' => (int) $r->paid_n, 'paid_value' => (int) $r->paid_value, 'refunded' => $revenue->cancellationRefunds($m['range']),
            ];
        }, array_reverse($buckets ?? $this->buckets($period)));

        $pending = Order::whereNotNull('cancelled_at')->where('payment_status', 'refund_pending')->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS v')->toBase()->first();

        return ['rows' => $rows, 'pending' => ['count' => (int) $pending->n, 'value' => (int) $pending->v]];
    }

    /**
     * Pendapatan per potongan periode untuk ekspor xlsx (D-130): angka grafik "Pendapatan per ..." (RevenueService, potongan yang sama),
     * tetapi terbaru di atas dan berlabel lengkap seperti tabel rekap lain.
     *
     * @param  array{from: string, to: string, group: string}  $period
     * @return list<array{label: string, gross: int, refunded: int, net: int, shipping_subsidy: int}>
     */
    public function revenueRows(array $period): array
    {
        $revenue = app(RevenueService::class);

        return array_map(fn (array $m) => [
            'label' => $m['label'],
            'gross' => $revenue->gross($m['range']),
            'refunded' => $revenue->refunded($m['range']),
            'net' => $revenue->net($m['range']),
            'shipping_subsidy' => $revenue->shippingSubsidy($m['range']),
        ], array_reverse($this->buckets($period)));
    }

    /**
     * Sepuluh produk teratas di seluruh periode yang dipilih.
     *
     * @param  list<array{label: string, short: string, range: array{0: CarbonImmutable, 1: CarbonImmutable}}>  $buckets
     * @return list<array{name: string, qty: int, revenue: int|null}>
     */
    private function topProducts(array $buckets, bool $money): array
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', 'paid')->whereBetween('orders.paid_at', [$buckets[0]['range'][0], $buckets[array_key_last($buckets)]['range'][1]])
            ->groupBy('order_items.product_name_snapshot')
            ->selectRaw('order_items.product_name_snapshot as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total) as revenue')
            ->orderByDesc($money ? 'revenue' : 'qty')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => $money ? (int) $r->revenue : null])->all();
    }

    /** @return array{active: int, ending_soon: int, rows: list<array<string, mixed>>} */
    private function vouchers(CarbonImmutable $now): array
    {
        $live = fn ($q) => $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now));

        return [
            'active' => Voucher::where($live)->count(),
            'ending_soon' => Voucher::where($live)->whereBetween('ends_at', [$now, $now->addDays(7)])->count(),
            'rows' => Voucher::orderByDesc('used_count')->orderByDesc('id')->limit(10)->get()->map(fn (Voucher $v) => [
                'code' => $v->code,
                'value' => $v->type === 'percent' ? $v->value.'%' : 'Rp'.number_format((int) $v->value, 0, ',', '.'),
                'used' => (int) $v->used_count,
                'quota' => $v->quota,
                'status' => ! $v->is_active ? 'inactive' : ($v->ends_at && $v->ends_at->lt($now) ? 'ended' : ($v->starts_at && $v->starts_at->gt($now) ? 'upcoming' : 'live')),
                'ends_at' => $v->ends_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function campaigns(CarbonImmutable $now): array
    {
        return Campaign::orderByDesc('starts_at')->limit(8)->get()->map(fn (Campaign $c) => [
            'title' => $c->title,
            'is_flash' => $c->is_flash,
            'starts_at' => $c->starts_at->toIso8601String(),
            'ends_at' => $c->ends_at->toIso8601String(),
            'status' => ! $c->is_active ? 'inactive' : ($c->ends_at->lt($now) ? 'ended' : ($c->starts_at->gt($now) ? 'upcoming' : 'live')),
        ])->all();
    }

    /** @return list<array{type: string, published: int, draft: int}> */
    private function contents(): array
    {
        return PromoContent::get(['type', 'is_published'])->groupBy('type')->sortKeys()
            ->map(fn ($rows, $type) => ['type' => $type, 'published' => $rows->where('is_published', true)->count(), 'draft' => $rows->where('is_published', false)->count()])->values()->all();
    }
}
