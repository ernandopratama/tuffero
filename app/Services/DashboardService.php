<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\PromoContent;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan dashboard admin. Setiap widget memeriksa izin sendiri, jadi data yang bukan hak sebuah role
 * (harga untuk Gudang, data pelanggan untuk Marketing) tidak pernah keluar dari server.
 */
class DashboardService
{
    /** PROPOSED (OQ-26): batas stok tersedia di bawah angka ini dianggap menipis. Belum diputuskan tim. */
    public const LOW_STOCK = 10;

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    /** @var array<string, array{kpis: list<string>, chart: string, gauge: string, lists: list<string>}> */
    private const LAYOUT = [
        'super-admin' => ['kpis' => ['orders_today', 'staff_active', 'attention_total'], 'chart' => 'orders_monthly', 'gauge' => 'mfa', 'lists' => ['audit_recent', 'attention', 'top_products']],
        'owner' => ['kpis' => ['revenue_month', 'orders_month', 'cancel_final_pending'], 'chart' => 'revenue_monthly', 'gauge' => 'cancel_on_time', 'lists' => ['cancel_final_queue', 'attention', 'top_products']],
        'store-admin' => ['kpis' => ['to_process', 'low_stock', 'vouchers_active'], 'chart' => 'orders_daily', 'gauge' => 'shipped_today', 'lists' => ['cancel_acc_queue', 'stock_alerts', 'top_products']],
        'customer-service' => ['kpis' => ['cancel_validate_pending', 'cancel_overdue', 'orders_today'], 'chart' => 'cancel_weekly', 'gauge' => 'cancel_on_time', 'lists' => ['cancel_validate_queue', 'cancel_overdue_list', 'orders_recent']],
        'warehouse' => ['kpis' => ['to_pack', 'awaiting_waybill', 'low_stock'], 'chart' => 'stock_monthly', 'gauge' => 'packed_today', 'lists' => ['pack_queue', 'stock_alerts', 'stock_activity']],
        'marketing' => ['kpis' => ['vouchers_active', 'content_published', 'products_published'], 'chart' => 'sales_summary', 'gauge' => 'voucher_quota', 'lists' => ['vouchers', 'contents', 'top_products']],
    ];

    private CarbonImmutable $now;

    /** @var Collection<int, object>|null */
    private ?Collection $stockLevels = null;

    public function __construct()
    {
        $this->now = CarbonImmutable::now();
    }

    /** @return array<string, mixed>|null null bila akun tidak punya role dashboard. */
    public function build(User $user): ?array
    {
        $role = $user->getRoleNames()->first();
        $layout = self::LAYOUT[$role] ?? null;

        if (! $layout) {
            return null;
        }

        $attention = $this->attention($user);

        return [
            'role' => $role,
            'generated_at' => $this->now->toIso8601String(),
            'attention' => $attention,
            'summary' => $this->summary($attention),
            'kpis' => collect($layout['kpis'])->map(fn (string $key) => $this->kpi($key, $user, $attention))->filter()->values()->all(),
            'chart' => $this->chart($layout['chart'], $user),
            'gauge' => $this->gauge($layout['gauge'], $user),
            'lists' => collect($layout['lists'])->map(fn (string $key) => $this->list($key, $user, $attention))->filter()->values()->all(),
        ];
    }

    // ---------- perhatian ----------

    /**
     * @return list<array{key: string, text: string, tone: string, count: int, to: string}>
     */
    private function attention(User $user): array
    {
        $items = [];
        $add = function (string $key, int $count, string $text, string $tone, string $to) use (&$items) {
            if ($count > 0) {
                $items[] = ['key' => $key, 'text' => str_replace(':n', (string) $count, $text), 'tone' => $tone, 'count' => $count, 'to' => $to];
            }
        };

        // Gudang juga boleh mengubah status, tetapi ringkasannya sudah dihitung sebagai antrean kemas.
        if ($user->can('orders.update_status') && ! $user->can('orders.view_fulfillment')) {
            $add('to_process', Order::where('status', Order::PAID)->count(), ':n pesanan terbayar perlu diproses', 'warn', 'AdminOrders');
        }

        if ($user->can('orders.view_fulfillment')) {
            $add('to_pack', $this->toPackCount(), ':n pesanan perlu dikemas', 'warn', 'AdminFulfillment');
        }

        if ($user->can('shipments.manage')) {
            $add('awaiting_waybill', $this->awaitingWaybillCount(), ':n pesanan menunggu nomor resi', 'warn', 'AdminShipments');
        }

        if ($user->can('orders.cancel_request')) {
            $add('cancel_validate', $this->cancelCount(OrderCancellationRequest::SUBMITTED), ':n pengajuan pembatalan menunggu validasi Anda', 'warn', 'AdminCancellations');
        }

        if ($user->can('orders.cancel')) {
            $add('cancel_acc', $this->cancelCount(OrderCancellationRequest::VALIDATED), ':n pengajuan pembatalan menunggu ACC Anda', 'warn', 'AdminCancellations');
            $add('refund_mark', $this->refundPendingCount(), ':n dana pembatalan perlu ditandai sudah dikembalikan', 'warn', 'AdminCancellations');
        }

        if ($user->can('orders.cancel_final')) {
            $add('cancel_final', $this->cancelCount(OrderCancellationRequest::STORE_APPROVED), ':n pembatalan menunggu ACC Anda', 'warn', 'AdminCancellations');
        }

        if ($user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'])) {
            $add('cancel_overdue', $this->overdueQuery()->count(), ':n pengajuan pembatalan lewat batas proses', 'bad', 'AdminCancellations');
        }

        if ($user->can('stock.view')) {
            $levels = $this->stockLevels();
            $add('stock_out', $levels->where('available', '<=', 0)->count(), ':n produk stok habis', 'bad', 'AdminStock');
            $add('stock_low', $levels->where('available', '>', 0)->where('available', '<', self::LOW_STOCK)->count(), ':n produk stok menipis', 'warn', 'AdminStock');
        }

        if ($user->can('users.manage')) {
            $add('staff_no_mfa', $this->staff()->whereNull('mfa_totp_confirmed_at')->count(), ':n akun staf belum memakai MFA', 'warn', 'AdminUsers');
        }

        if ($user->can('vouchers.manage')) {
            $add('voucher_ending', Voucher::where('is_active', true)->whereBetween('ends_at', [$this->now, $this->now->addDays(7)])->count(), ':n voucher berakhir dalam 7 hari', 'warn', 'AdminVouchers');
        }

        if ($user->can('content.manage')) {
            $add('content_draft', PromoContent::where('is_published', false)->count(), ':n konten promo masih draf', 'warn', 'AdminContents');
        }

        return $items;
    }

    /** @param list<array{text: string}> $attention */
    private function summary(array $attention): string
    {
        if ($attention === []) {
            return 'Tidak ada yang perlu ditindaklanjuti sekarang.';
        }

        $lines = array_map(fn (array $item) => $item['text'], array_slice($attention, 0, 3));
        $lines[0] = mb_strtoupper(mb_substr($lines[0], 0, 1)).mb_substr($lines[0], 1);

        return implode('. ', $lines).'.';
    }

    // ---------- KPI ----------

    /**
     * @param  list<array{key: string, count: int}>  $attention
     * @return array<string, mixed>|null
     */
    private function kpi(string $key, User $user, array $attention): ?array
    {
        $count = fn (string $attentionKey) => collect($attention)->firstWhere('key', $attentionKey)['count'] ?? 0;

        return match ($key) {
            'orders_today' => $user->can('orders.view') ? $this->ordersTodayKpi() : null,
            'revenue_month' => $user->can('payments.view') ? $this->revenueKpi() : null,
            'orders_month' => $user->can('orders.view') ? [
                'key' => $key, 'label' => 'Pesanan bulan ini', 'value' => Order::whereBetween('paid_at', $this->monthRange(0))->count(), 'format' => 'number', 'icon' => 'receipt', 'tone' => 'neutral',
                'delta' => null, 'foot' => Order::whereBetween('cancelled_at', $this->monthRange(0))->count().' dibatalkan', 'spark' => null,
            ] : null,
            'cancel_final_pending' => $user->can('orders.cancel_final') ? [
                'key' => $key, 'label' => 'Menunggu ACC Anda', 'value' => $this->cancelCount(OrderCancellationRequest::STORE_APPROVED), 'format' => 'number', 'icon' => 'check', 'tone' => 'bad',
                'delta' => null, 'foot' => 'Pesanan sudah dibayar', 'spark' => null,
            ] : null,
            'staff_active' => $user->can('users.manage') ? [
                'key' => $key, 'label' => 'Akun staf aktif', 'value' => $this->staff()->count(), 'format' => 'number', 'icon' => 'user', 'tone' => 'neutral', 'delta' => null,
                'foot' => 'MFA aktif di '.$this->staff()->whereNotNull('mfa_totp_confirmed_at')->count().' akun', 'spark' => null,
            ] : null,
            'attention_total' => [
                'key' => $key, 'label' => 'Perlu tindakan', 'value' => collect($attention)->sum('count'), 'format' => 'number', 'icon' => 'alert', 'tone' => 'bad', 'delta' => null,
                'foot' => 'Gabungan semua peringatan', 'spark' => null,
            ],
            'to_process' => $user->can('orders.update_status') ? [
                'key' => $key, 'label' => 'Perlu diproses', 'value' => $count('to_process'), 'format' => 'number', 'icon' => 'receipt', 'tone' => 'bad', 'delta' => null,
                'foot' => 'Sudah dibayar, belum diproses', 'spark' => null,
            ] : null,
            'low_stock' => $user->can('stock.view') ? [
                'key' => $key, 'label' => 'Stok menipis', 'value' => $count('stock_low') + $count('stock_out'), 'format' => 'number', 'icon' => 'warehouse', 'tone' => 'warn', 'delta' => null,
                'foot' => $count('stock_out').' habis, batas '.self::LOW_STOCK, 'spark' => null,
            ] : null,
            'vouchers_active' => $user->can('vouchers.view') ? [
                'key' => $key, 'label' => 'Voucher aktif', 'value' => $this->activeVouchers()->count(), 'format' => 'number', 'icon' => 'ticket', 'tone' => 'neutral', 'delta' => null,
                'foot' => $this->activeVouchers()->whereBetween('ends_at', [$this->now, $this->now->addDays(7)])->count().' berakhir minggu ini', 'spark' => null,
            ] : null,
            'cancel_validate_pending' => $user->can('orders.cancel_request') ? [
                'key' => $key, 'label' => 'Menunggu validasi', 'value' => $this->cancelCount(OrderCancellationRequest::SUBMITTED), 'format' => 'number', 'icon' => 'x', 'tone' => 'bad', 'delta' => null,
                'foot' => 'Pengajuan dari pelanggan', 'spark' => null,
            ] : null,
            'cancel_overdue' => $user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final']) ? [
                'key' => $key, 'label' => 'Lewat batas proses', 'value' => $this->overdueQuery()->count(), 'format' => 'number', 'icon' => 'clock', 'tone' => 'bad', 'delta' => null,
                'foot' => 'Sistem hanya mengingatkan', 'spark' => null,
            ] : null,
            'to_pack' => $user->can('orders.view_fulfillment') ? [
                'key' => $key, 'label' => 'Perlu dikemas', 'value' => $this->toPackCount(), 'format' => 'number', 'icon' => 'box', 'tone' => 'bad', 'delta' => null,
                'foot' => 'Dibayar dan sedang diproses', 'spark' => null,
            ] : null,
            'awaiting_waybill' => $user->can('shipments.manage') ? [
                'key' => $key, 'label' => 'Menunggu resi', 'value' => $this->awaitingWaybillCount(), 'format' => 'number', 'icon' => 'truck', 'tone' => 'neutral', 'delta' => null,
                'foot' => 'Sudah diproses, belum dikirim', 'spark' => null,
            ] : null,
            'content_published' => $user->can('content.view') ? [
                'key' => $key, 'label' => 'Konten tayang', 'value' => PromoContent::where('is_published', true)->count(), 'format' => 'number', 'icon' => 'sparkles', 'tone' => 'neutral', 'delta' => null,
                'foot' => PromoContent::where('is_published', false)->count().' draf', 'spark' => null,
            ] : null,
            'products_published' => $user->can('products.view') ? [
                'key' => $key, 'label' => 'Produk tayang', 'value' => Product::where('is_published', true)->count(), 'format' => 'number', 'icon' => 'tag', 'tone' => 'neutral', 'delta' => null,
                'foot' => Product::where('is_published', false)->count().' draf', 'spark' => null,
            ] : null,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function ordersTodayKpi(): array
    {
        $today = $this->now->startOfDay();
        $yesterday = Order::whereBetween('created_at', [$today->subDay(), $today])->count();
        $value = Order::where('created_at', '>=', $today)->count();

        return [
            'key' => 'orders_today', 'label' => 'Pesanan hari ini', 'value' => $value, 'format' => 'number', 'icon' => 'receipt', 'tone' => 'warn',
            'delta' => $this->delta($value, $yesterday), 'foot' => $yesterday > 0 ? 'Dibanding kemarin' : 'Kemarin belum ada pesanan',
            'spark' => collect(range(6, 0))->map(fn (int $i) => Order::whereBetween('created_at', [$today->subDays($i), $today->subDays($i)->endOfDay()])->count())->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function revenueKpi(): array
    {
        $sum = fn (int $back) => (int) Order::where('payment_status', 'paid')->whereBetween('paid_at', $this->monthRange($back))->sum('total');
        $value = $sum(0);
        $previous = $sum(1);

        return ['key' => 'revenue_month', 'label' => 'Pendapatan bulan ini', 'value' => $value, 'format' => 'rupiah', 'icon' => 'wallet', 'tone' => 'warn', 'delta' => $this->delta($value, $previous), 'foot' => $previous > 0 ? 'Dibanding bulan lalu' : 'Bulan lalu belum ada pembanding', 'spark' => null];
    }

    private function delta(int $value, int $previous): ?float
    {
        return $previous > 0 ? round(($value - $previous) / $previous * 100, 1) : null;
    }

    // ---------- grafik ----------

    /** @return array<string, mixed>|null */
    private function chart(string $key, User $user): ?array
    {
        return match ($key) {
            'orders_monthly' => $user->can('orders.view') ? $this->chartData('Pesanan per bulan', 'Bulanan', 'number', $this->monthLabels(), [
                ['key' => 'paid', 'name' => 'Dibayar', 'tone' => 'success', 'values' => $this->monthly(fn ($r) => Order::whereBetween('paid_at', $r)->count())],
                ['key' => 'cancelled', 'name' => 'Dibatalkan', 'tone' => 'secondary', 'values' => $this->monthly(fn ($r) => Order::whereBetween('cancelled_at', $r)->count())],
            ]) : null,
            'revenue_monthly' => $user->can('payments.view') ? $this->chartData('Pendapatan per bulan', 'Bulanan', 'rupiah', $this->monthLabels(), [
                ['key' => 'revenue', 'name' => 'Pendapatan', 'tone' => 'primary', 'values' => $this->monthly(fn ($r) => (int) Order::where('payment_status', 'paid')->whereBetween('paid_at', $r)->sum('total'))],
                ['key' => 'refunded', 'name' => 'Dana dikembalikan', 'tone' => 'secondary', 'values' => $this->monthly(fn ($r) => (int) OrderCancellationRequest::join('orders', 'orders.id', '=', 'order_cancellation_requests.order_id')->whereBetween('order_cancellation_requests.refunded_at', $r)->sum('orders.total'))],
            ]) : null,
            'orders_daily' => $user->can('orders.view') ? $this->chartData('Pesanan per hari', '7 hari', 'number', $this->dayLabels(), [
                ['key' => 'paid', 'name' => 'Dibayar', 'tone' => 'success', 'values' => $this->daily(fn ($r) => Order::whereBetween('paid_at', $r)->count())],
                ['key' => 'shipped', 'name' => 'Dikirim', 'tone' => 'accent', 'values' => $this->daily(fn ($r) => Shipment::whereBetween('shipped_at', $r)->count())],
            ]) : null,
            'cancel_weekly' => $user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final']) ? $this->chartData('Pengajuan pembatalan per minggu', '8 minggu', 'number', $this->weekLabels(), [
                ['key' => 'incoming', 'name' => 'Masuk', 'tone' => 'accent', 'values' => $this->weekly(fn ($r) => OrderCancellationRequest::whereBetween('created_at', $r)->count())],
                ['key' => 'done', 'name' => 'Selesai', 'tone' => 'success', 'values' => $this->weekly(fn ($r) => OrderCancellationRequest::whereIn('status', [OrderCancellationRequest::APPROVED, OrderCancellationRequest::REJECTED, OrderCancellationRequest::REFUNDED])->whereBetween('updated_at', $r)->count())],
            ]) : null,
            'stock_monthly' => $user->can('stock.view') ? $this->chartData('Stok masuk dan keluar', 'Bulanan', 'number', $this->monthLabels(), [
                ['key' => 'in', 'name' => 'Stok masuk', 'tone' => 'success', 'values' => $this->monthly(fn ($r) => (int) DB::table('stock_movements')->where('quantity_change', '>', 0)->whereBetween('created_at', $r)->sum('quantity_change'))],
                ['key' => 'out', 'name' => 'Stok keluar', 'tone' => 'secondary', 'values' => $this->monthly(fn ($r) => abs((int) DB::table('stock_movements')->where('quantity_change', '<', 0)->whereBetween('created_at', $r)->sum('quantity_change')))],
            ]) : null,
            'sales_summary' => $user->can('reports.view_summary') ? $this->chartData('Ringkasan penjualan', 'Bulanan', 'number', $this->monthLabels(), [
                ['key' => 'orders', 'name' => 'Pesanan', 'tone' => 'accent', 'values' => $this->monthly(fn ($r) => Order::where('payment_status', 'paid')->whereBetween('paid_at', $r)->count())],
                ['key' => 'units', 'name' => 'Produk terjual', 'tone' => 'primary', 'values' => $this->monthly(fn ($r) => (int) OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.payment_status', 'paid')->whereBetween('orders.paid_at', $r)->sum('order_items.quantity'))],
            ]) : null,
            default => null,
        };
    }

    /**
     * @param  list<string>  $labels
     * @param  list<array{key: string, name: string, tone: string, values: list<int>}>  $series
     * @return array<string, mixed>
     */
    private function chartData(string $title, string $range, string $format, array $labels, array $series): array
    {
        return ['title' => $title, 'range' => $range, 'format' => $format, 'labels' => $labels, 'series' => $series];
    }

    /** @return list<string> */
    private function monthLabels(): array
    {
        return array_map(fn (int $i) => self::MONTHS[$this->now->startOfMonth()->subMonths($i)->month - 1], range(9, 0));
    }

    /** @return list<string> */
    private function dayLabels(): array
    {
        return array_map(fn (int $i) => self::DAYS[$this->now->startOfDay()->subDays($i)->dayOfWeekIso - 1], range(6, 0));
    }

    /** @return list<string> */
    private function weekLabels(): array
    {
        return array_map(fn (int $i) => 'M'.(8 - $i), range(7, 0));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function monthRange(int $back): array
    {
        $start = $this->now->startOfMonth()->subMonths($back);

        return [$start, $start->endOfMonth()];
    }

    /** @return list<int> */
    private function monthly(callable $count): array
    {
        return array_map(fn (int $i) => $count($this->monthRange($i)), range(9, 0));
    }

    /** @return list<int> */
    private function daily(callable $count): array
    {
        return array_map(fn (int $i) => $count([$this->now->startOfDay()->subDays($i), $this->now->startOfDay()->subDays($i)->endOfDay()]), range(6, 0));
    }

    /** @return list<int> */
    private function weekly(callable $count): array
    {
        return array_map(fn (int $i) => $count([$this->now->startOfWeek()->subWeeks($i), $this->now->startOfWeek()->subWeeks($i)->endOfWeek()]), range(7, 0));
    }

    // ---------- gauge ----------

    /** @return array<string, mixed>|null */
    private function gauge(string $key, User $user): ?array
    {
        switch ($key) {
            case 'mfa':
                if (! $user->can('users.manage')) {
                    return null;
                }
                $total = $this->staff()->count();
                $with = $this->staff()->whereNotNull('mfa_totp_confirmed_at')->count();

                return $this->gaugeData('Keamanan akun', 'Akun staf dengan MFA', $total > 0 ? (int) round($with / $total * 100) : null, [['Akun dengan MFA', "$with dari $total"], ['Belum aktif', ($total - $with).' akun']], ($total - $with).' akun belum aktif MFA');
            case 'cancel_on_time':
                if (! $user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'])) {
                    return null;
                }
                $finished = OrderCancellationRequest::whereIn('status', [OrderCancellationRequest::APPROVED, OrderCancellationRequest::REJECTED, OrderCancellationRequest::REFUNDED])
                    ->where('created_at', '>=', $this->now->subDays(90))->get();
                $onTime = $finished->filter(fn (OrderCancellationRequest $r) => ($r->refunded_at ?? $r->rejected_at ?? $r->owner_approved_at ?? $r->store_approved_at ?? $r->updated_at)->lte($r->due_at))->count();
                $days = (int) (Setting::where('key', 'cancellation.process_days_max')->value('value') ?: 7);

                return $this->gaugeData('Pembatalan tepat waktu', "Selesai dalam $days hari", $finished->isEmpty() ? null : (int) round($onTime / $finished->count() * 100),
                    [['Selesai 90 hari', (string) $finished->count()], ['Lewat batas', (string) $this->overdueQuery()->count()]], 'Batas proses '.$days.' hari (D-043)');
            case 'shipped_today':
                if (! $user->can('orders.update_status')) {
                    return null;
                }
                $shipped = Shipment::where('shipped_at', '>=', $this->now->startOfDay())->count();
                $open = Order::whereIn('status', [Order::PAID, Order::PROCESSING])->count();

                return $this->gaugeData('Pengiriman hari ini', 'Terkirim dari yang perlu dikirim', $shipped + $open > 0 ? (int) round($shipped / ($shipped + $open) * 100) : null, [['Terkirim hari ini', (string) $shipped], ['Belum dikirim', (string) $open]], $open.' pesanan belum dikirim');
            case 'packed_today':
                if (! $user->can('orders.view_fulfillment')) {
                    return null;
                }
                $packed = OrderStatusHistory::where('status_type', 'order')->where('to_status', Order::PROCESSING)->where('created_at', '>=', $this->now->startOfDay())->count();
                $open = Order::where('status', Order::PAID)->count();

                return $this->gaugeData('Pengemasan hari ini', 'Diproses dari yang perlu dikemas', $packed + $open > 0 ? (int) round($packed / ($packed + $open) * 100) : null, [['Diproses hari ini', (string) $packed], ['Belum dikemas', (string) $open]], $open.' pesanan belum dikemas');
            case 'voucher_quota':
                if (! $user->can('vouchers.view')) {
                    return null;
                }
                $quota = (int) $this->activeVouchers()->whereNotNull('quota')->sum('quota');
                $used = (int) $this->activeVouchers()->whereNotNull('quota')->sum('used_count');

                return $this->gaugeData('Kuota voucher', 'Terpakai dari kuota', $quota > 0 ? (int) round($used / $quota * 100) : null, [['Terpakai', (string) $used], ['Kuota', (string) $quota]], $this->activeVouchers()->count().' voucher aktif');
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $metrics
     * @return array<string, mixed>
     */
    private function gaugeData(string $title, string $caption, ?int $percent, array $metrics, string $note): array
    {
        return ['title' => $title, 'caption' => $caption, 'percent' => $percent, 'metrics' => array_map(fn (array $m) => ['label' => $m[0], 'value' => $m[1]], $metrics), 'note' => $note];
    }

    // ---------- daftar ----------

    /**
     * @param  list<array<string, mixed>>  $attention
     * @return array<string, mixed>|null
     */
    private function list(string $key, User $user, array $attention): ?array
    {
        return match ($key) {
            'audit_recent' => $user->can('audit.view') ? $this->listData($key, 'Aktivitas terbaru', 'Dari audit log', AuditLog::with('actor:id,name')->latest('id')->limit(3)->get()->map(fn (AuditLog $log) => [
                'icon' => 'shield', 'tone' => 'neutral', 'title' => $log->action, 'action' => $log->action, 'subtitle' => ($log->actor->name ?? 'Sistem'), 'at' => $log->created_at?->toIso8601String(),
            ])->all(), 'Belum ada aktivitas.') : null,
            'attention' => $this->listData($key, 'Perlu perhatian', null, collect($attention)->take(3)->map(fn (array $a) => [
                'kind' => 'alert', 'icon' => $a['tone'] === 'bad' ? 'alert' : 'clock', 'tone' => $a['tone'], 'title' => $a['text'], 'subtitle' => null, 'to' => $a['to'],
            ])->all(), 'Tidak ada yang perlu ditindaklanjuti.'),
            'top_products' => $user->canAny(['orders.view', 'reports.view_summary']) ? $this->topProducts($user) : null,
            'cancel_final_queue' => $user->can('orders.cancel_final') ? $this->cancelQueue($key, 'Menunggu keputusan Anda', OrderCancellationRequest::STORE_APPROVED, 'ACC Pemilik') : null,
            'cancel_acc_queue' => $user->can('orders.cancel') ? $this->cancelQueue($key, 'Antrean ACC pembatalan', OrderCancellationRequest::VALIDATED, 'Divalidasi CS') : null,
            'cancel_validate_queue' => $user->can('orders.cancel_request') ? $this->cancelQueue($key, 'Antrean validasi', OrderCancellationRequest::SUBMITTED, 'Baru') : null,
            'cancel_overdue_list' => $user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final']) ? $this->listData($key, 'Lewat batas proses', null, $this->overdueQuery()->with('order:id,order_number,total')->orderBy('due_at')->limit(3)->get()->map(fn (OrderCancellationRequest $r) => [
                'kind' => 'alert', 'icon' => 'clock', 'tone' => 'bad', 'title' => $r->request_number.' lewat batas', 'subtitle' => 'Pesanan '.$r->order->order_number.'. Pantau sampai selesai.', 'to' => 'AdminCancellations',
            ])->all(), 'Tidak ada pengajuan yang lewat batas.') : null,
            'orders_recent' => $user->can('orders.view') ? $this->listData($key, 'Pesanan terbaru', null, Order::latest('id')->limit(3)->get()->map(fn (Order $o) => [
                'icon' => 'receipt', 'tone' => 'neutral', 'title' => $o->order_number.' · '.$o->customer_name, 'subtitle' => strtoupper($o->shipping_courier).' '.strtoupper($o->shipping_service),
                'status' => $o->status, 'to' => 'AdminOrders',
            ])->all(), 'Belum ada pesanan.') : null,
            'pack_queue' => $user->can('orders.view_fulfillment') ? $this->listData($key, 'Antrean kemas', 'Tanpa harga dan data pembayaran', Order::with('items')->whereIn('status', [Order::PAID, Order::PROCESSING])->orderBy('paid_at')->limit(3)->get()->map(fn (Order $o) => [
                'icon' => 'box', 'tone' => 'warn', 'title' => $o->order_number, 'subtitle' => $o->items->map(fn ($i) => $i->quantity.' × '.$i->product_name_snapshot)->implode(', '), 'status' => $o->status, 'to' => 'AdminFulfillment',
            ])->all(), 'Tidak ada pesanan yang perlu dikemas.') : null,
            'stock_alerts' => $user->can('stock.view') ? $this->listData($key, 'Stok menipis', null, $this->stockLevels()->where('available', '<', self::LOW_STOCK)->sortBy('available')->take(3)->map(fn ($s) => [
                'kind' => 'alert', 'icon' => $s->available <= 0 ? 'alert' : 'warehouse', 'tone' => $s->available <= 0 ? 'bad' : 'warn',
                'title' => ($s->available <= 0 ? 'Habis: ' : 'Tipis: ').$s->product, 'subtitle' => 'Tersedia '.max($s->available, 0).' · '.$s->sku, 'to' => 'AdminStock',
            ])->values()->all(), 'Semua stok aman.') : null,
            'stock_activity' => $user->can('stock.view') ? $this->stockActivity($key) : null,
            'vouchers' => $user->can('vouchers.view') ? $this->listData($key, 'Voucher', null, Voucher::latest('id')->limit(3)->get()->map(fn (Voucher $v) => [
                'icon' => 'ticket', 'tone' => $v->is_active ? 'ok' : 'neutral', 'title' => $v->code, 'subtitle' => ($v->type === 'percent' ? $v->value.'%' : 'Rp'.number_format((int) $v->value, 0, ',', '.')).($v->ends_at ? ' · sampai '.$v->ends_at->format('d M Y') : ''),
                'chip' => ['text' => $v->is_active ? 'Aktif' : 'Nonaktif', 'tone' => $v->is_active ? 'ok' : 'neutral'], 'to' => 'AdminVouchers',
            ])->all(), 'Belum ada voucher.') : null,
            'contents' => $user->can('content.view') ? $this->listData($key, 'Konten promo', null, PromoContent::latest('id')->limit(3)->get()->map(fn (PromoContent $c) => [
                'icon' => 'sparkles', 'tone' => $c->is_published ? 'ok' : 'warn', 'title' => $c->title, 'subtitle' => ['banner' => 'Banner', 'text' => 'Teks promo', 'video' => 'Video'][$c->type] ?? $c->type,
                'chip' => ['text' => $c->is_published ? 'Tayang' : 'Draf', 'tone' => $c->is_published ? 'ok' : 'warn'], 'to' => 'AdminContents',
            ])->all(), 'Belum ada konten.') : null,
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function listData(string $key, string $title, ?string $subtitle, array $rows, string $empty): array
    {
        return ['key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'rows' => $rows, 'empty' => $empty];
    }

    /** @return array<string, mixed> */
    private function cancelQueue(string $key, string $title, string $status, string $chip): array
    {
        $rows = OrderCancellationRequest::with('order:id,order_number,total,payment_status')->where('status', $status)->orderBy('due_at')->limit(3)->get()->map(fn (OrderCancellationRequest $r) => [
            'icon' => 'x', 'tone' => 'warn', 'title' => $r->request_number, 'subtitle' => $r->order->order_number.($r->order->payment_status === 'paid' ? ' · Sudah dibayar' : ' · Belum dibayar'),
            'chip' => ['text' => $chip, 'tone' => 'warn'], 'to' => 'AdminCancellations',
        ])->all();

        return $this->listData($key, $title, null, $rows, 'Tidak ada pengajuan yang menunggu.');
    }

    /** @return array<string, mixed> */
    private function topProducts(User $user): array
    {
        $money = $user->can('payments.view');
        $rows = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', 'paid')->where('orders.paid_at', '>=', $this->now->subDays(30))
            ->groupBy('order_items.product_variant_id', 'order_items.product_name_snapshot')
            ->selectRaw('order_items.product_variant_id as variant_id, order_items.product_name_snapshot as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total) as revenue')
            ->orderByDesc($money ? 'revenue' : 'qty')->limit(3)->get();

        $photos = ProductVariant::with('product.images')->whereIn('id', $rows->pluck('variant_id'))->get()->mapWithKeys(fn (ProductVariant $v) => [
            $v->id => $v->product?->images->sortBy('sort_order')->first(),
        ]);

        return $this->listData('top_products', 'Produk terlaris', '30 hari terakhir'.($money ? ', berdasarkan pendapatan' : ', berdasarkan jumlah'), $rows->map(function ($row) use ($photos, $money) {
            $image = $photos[$row->variant_id] ?? null;

            return [
                'icon' => 'tag', 'tone' => 'neutral', 'title' => $row->name, 'subtitle' => ((int) $row->qty).' terjual',
                'photo' => $image instanceof ProductImage ? '/storage/'.$image->path : null, 'amount' => $money ? (int) $row->revenue : null,
            ];
        })->all(), 'Belum ada penjualan dalam 30 hari terakhir.');
    }

    /** @return array<string, mixed> */
    private function stockActivity(string $key): array
    {
        $rows = DB::table('stock_movements')->join('inventory_stocks', 'inventory_stocks.id', '=', 'stock_movements.inventory_stock_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('stock_movements.type', ['restock', 'adjustment', 'sale', 'return'])->where('stock_movements.quantity_change', '!=', 0)
            ->orderByDesc('stock_movements.id')->limit(3)
            ->get(['stock_movements.type', 'stock_movements.quantity_change', 'stock_movements.created_at', 'products.name as product', 'product_variants.sku'])
            ->map(fn ($m) => [
                'icon' => 'warehouse', 'tone' => $m->quantity_change > 0 ? 'ok' : 'bad', 'title' => ($m->quantity_change > 0 ? 'Stok masuk +' : 'Stok keluar ').$m->quantity_change,
                'subtitle' => $m->product.' · '.$m->sku, 'chip' => ['text' => $m->quantity_change > 0 ? 'Masuk' : 'Keluar', 'tone' => $m->quantity_change > 0 ? 'ok' : 'bad'], 'at' => CarbonImmutable::parse($m->created_at)->toIso8601String(), 'to' => 'AdminStock',
            ])->all();

        return $this->listData($key, 'Aktivitas stok', null, $rows, 'Belum ada perubahan stok.');
    }

    // ---------- kueri bersama ----------

    private function cancelCount(string $status): int
    {
        return OrderCancellationRequest::where('status', $status)->count();
    }

    private function refundPendingCount(): int
    {
        return OrderCancellationRequest::where('status', OrderCancellationRequest::APPROVED)->whereHas('order', fn ($q) => $q->where('payment_status', 'refund_pending'))->count();
    }

    /** @return Builder<OrderCancellationRequest> */
    private function overdueQuery(): Builder
    {
        return OrderCancellationRequest::whereIn('status', [...OrderCancellationRequest::OPEN, OrderCancellationRequest::APPROVED])
            ->where('due_at', '<', $this->now)->whereHas('order', fn ($q) => $q->where('payment_status', '!=', 'cancelled'));
    }

    private function toPackCount(): int
    {
        return Order::whereIn('status', [Order::PAID, Order::PROCESSING])->count();
    }

    private function awaitingWaybillCount(): int
    {
        return Order::where('status', Order::PROCESSING)->whereDoesntHave('shipment', fn ($q) => $q->whereNotNull('waybill_id'))->count();
    }

    /** @return Builder<Voucher> */
    private function activeVouchers(): Builder
    {
        return Voucher::where('is_active', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $this->now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $this->now));
    }

    /** @return Builder<User> */
    private function staff(): Builder
    {
        return User::where('is_active', true)->whereHas('roles');
    }

    /** @return Collection<int, object> */
    private function stockLevels(): Collection
    {
        return $this->stockLevels ??= ProductVariant::query()->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventory_stocks', 'inventory_stocks.product_variant_id', '=', 'product_variants.id')
            ->where('product_variants.is_active', true)->whereNull('products.deleted_at')
            ->groupBy('product_variants.id', 'product_variants.sku', 'products.name')
            ->selectRaw('product_variants.id, product_variants.sku, products.name as product, COALESCE(SUM(inventory_stocks.quantity - inventory_stocks.reserved), 0) as available')
            ->get();
    }
}
