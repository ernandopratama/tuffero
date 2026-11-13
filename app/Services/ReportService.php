<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Order;
use App\Models\PromoContent;
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

    /** Jumlah bulan pada grafik, termasuk bulan ini. */
    private const SPAN = 10;

    /** @return array<string, mixed> */
    public function build(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $money = $user->can('reports.view');

        return [
            'money' => $money,
            'generated_at' => $now->toIso8601String(),
            'sales' => $this->sales($now, $money),
            'top_products' => $this->topProducts($now, $money),
            'vouchers' => $this->vouchers($now),
            'campaigns' => $this->campaigns($now),
            'contents' => $this->contents(),
        ];
    }

    /** @return array<string, mixed> */
    private function sales(CarbonImmutable $now, bool $money): array
    {
        // Bulan mengikuti waktu Jakarta (seperti StoreSite dan DashboardService); database menyimpan UTC, jadi batasnya diubah ke UTC
        // sebelum dipakai sebagai binding kueri (Laravel memformat Carbon dengan zonanya sendiri). Label memakai bulan Jakarta.
        $local = $now->setTimezone('Asia/Jakarta');
        $months = array_map(fn (int $i) => $local->startOfMonth()->subMonths($i), range(self::SPAN - 1, 0));
        $range = fn (CarbonImmutable $start) => [$start->setTimezone('UTC'), $start->endOfMonth()->setTimezone('UTC')];

        $series = [
            ['key' => 'orders', 'name' => 'Pesanan', 'tone' => 'accent', 'values' => array_map(fn ($m) => Order::where('payment_status', 'paid')->whereBetween('paid_at', $range($m))->count(), $months)],
            ['key' => 'units', 'name' => 'Produk terjual', 'tone' => 'primary', 'values' => array_map(fn ($m) => (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.payment_status', 'paid')->whereBetween('orders.paid_at', $range($m))->sum('order_items.quantity'), $months)],
        ];

        $chart = ['title' => 'Penjualan per bulan', 'range' => 'Bulanan', 'format' => 'number', 'labels' => array_map(fn ($m) => self::MONTHS[$m->month - 1], $months), 'series' => $series];

        $revenue = null;
        if ($money) {
            $revenue = ['title' => 'Pendapatan per bulan', 'range' => 'Bulanan', 'format' => 'rupiah', 'labels' => $chart['labels'], 'series' => [
                // Kotor, dana dikembalikan, dan bersih memakai definisi yang sama dengan ringkasan Pemilik (RevenueService).
                ['key' => 'revenue', 'name' => 'Pendapatan kotor', 'tone' => 'primary', 'values' => array_map(fn ($m) => app(RevenueService::class)->gross($range($m)), $months)],
                ['key' => 'refunded', 'name' => 'Dana dikembalikan', 'tone' => 'secondary', 'values' => array_map(fn ($m) => app(RevenueService::class)->refunded($range($m)), $months)],
                ['key' => 'net', 'name' => 'Pendapatan bersih', 'tone' => 'success', 'values' => array_map(fn ($m) => app(RevenueService::class)->net($range($m)), $months)],
            ]];
        }

        return ['chart' => $chart, 'revenue_chart' => $revenue];
    }

    /** @return list<array{name: string, qty: int, revenue: int|null}> */
    private function topProducts(CarbonImmutable $now, bool $money): array
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', 'paid')->where('orders.paid_at', '>=', $now->subDays(30))
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
