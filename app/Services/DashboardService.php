<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\MarketingDraft;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\PromoContent;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan dashboard admin. Setiap widget memeriksa izin sendiri, jadi data yang bukan hak sebuah role
 * (harga untuk Gudang, data pelanggan untuk Marketing) tidak pernah keluar dari server.
 */
class DashboardService
{
    /** PROPOSED (OQ-26): batas stok tersedia di bawah angka ini dianggap menipis. Belum diputuskan tim. */
    public const LOW_STOCK = 10;

    /** Kunci cache ringkasan pemeriksaan Super Admin; dibuang oleh Cek ke API dan Tandai sudah ditangani. */
    public const HEALTH_CACHE_KEY = 'dashboard:health:v2';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    /** @var array<string, array{kpis: list<string>, chart: string, gauge: string, lists: list<string>}> */
    private const LAYOUT = [
        'super-admin' => ['kpis' => ['orders_today', 'staff_active', 'attention_total'], 'chart' => 'orders_monthly', 'gauge' => 'mfa', 'lists' => ['audit_recent', 'attention', 'integrations', 'top_products']],
        'owner' => ['kpis' => ['revenue_month', 'orders_month', 'cancel_final_pending', 'attention_total'], 'chart' => 'revenue_monthly', 'gauge' => 'cancel_on_time', 'lists' => ['cancel_final_queue', 'refund_decide_queue', 'draft_review_queue', 'attention', 'top_products', 'stock_alerts', 'audit_recent']],
        'store-admin' => ['kpis' => ['to_process', 'low_stock', 'vouchers_active', 'attention_total'], 'chart' => 'orders_daily', 'gauge' => 'shipped_today', 'lists' => ['cancel_acc_queue', 'stock_alerts', 'top_products']],
        'customer-service' => ['kpis' => ['cancel_validate_pending', 'refund_pending', 'cancel_overdue', 'attention_total'], 'chart' => 'cancel_weekly', 'gauge' => 'cancel_on_time', 'lists' => ['cancel_validate_queue', 'refund_queue', 'followup_due', 'shipment_problems', 'low_reviews', 'orders_recent']],
        'warehouse' => ['kpis' => ['to_pack', 'awaiting_waybill', 'low_stock', 'attention_total'], 'chart' => 'stock_monthly', 'gauge' => 'packed_today', 'lists' => ['pack_queue', 'stock_alerts', 'stock_activity']],
        'marketing' => ['kpis' => ['vouchers_active', 'content_published', 'products_published', 'attention_total'], 'chart' => 'sales_summary', 'gauge' => 'voucher_quota', 'lists' => ['vouchers', 'contents', 'top_products']],
    ];

    private CarbonImmutable $now;

    private ?int $lowStock = null;

    /** @var Collection<int, object>|null */
    private ?Collection $stockLevels = null;

    /** @var array<string, mixed>|null ringkasan pemeriksaan untuk satu permintaan; lihat health() */
    private ?array $health = null;

    /** Hari dan bulan toko mengikuti waktu Jakarta (seperti StoreSite), sedangkan database menyimpan UTC. */
    private const STORE_TZ = 'Asia/Jakarta';

    /** Waktu sekarang dalam zona toko; dipakai untuk menentukan batas hari, minggu, dan bulan. */
    private CarbonImmutable $local;

    public function __construct()
    {
        $this->now = CarbonImmutable::now();
        $this->local = $this->now->setTimezone(self::STORE_TZ);
    }

    /**
     * Batas waktu zona toko yang siap dipakai sebagai binding kueri. Laravel memformat Carbon dengan zonanya sendiri,
     * jadi harus diubah ke UTC dulu supaya cocok dengan kolom yang disimpan dalam UTC.
     */
    private function utc(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone('UTC');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} awal dan akhir hari (zona toko) sebagai UTC */
    private function dayRange(CarbonImmutable $day): array
    {
        return [$this->utc($day->startOfDay()), $this->utc($day->endOfDay())];
    }

    /** Batas stok menipis dari Pengaturan (`inventory.low_stock_threshold`); bawaan LOW_STOCK. */
    public static function lowStockLimit(): int
    {
        return (int) (Setting::where('key', 'inventory.low_stock_threshold')->value('value') ?? self::LOW_STOCK);
    }

    /** @return array<string, mixed>|null null bila akun tidak punya role dashboard. */
    public function build(User $user): ?array
    {
        // Akun dengan banyak role memakai role pertama menurut urutan LAYOUT (super-admin paling atas), bukan urutan acak dari database.
        $role = collect(array_keys(self::LAYOUT))->first(fn (string $name) => $user->hasRole($name));
        $layout = $role ? self::LAYOUT[$role] : null;

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
            // Alat CS (D-106): jam operasional toko dan angka layanan 30 hari. Hanya untuk akun yang memegang izin alat CS.
            'store_hours' => $role === 'customer-service' && $user->can('followups.manage') ? app(ServiceStatsService::class)->storeHours($this->now) : null,
            'service_stats' => $role === 'customer-service' && $user->can('followups.manage') ? app(ServiceStatsService::class)->tiles($user, $this->now) : [],
            // Pemilik, dan Super Admin yang memegang izin yang sama (keputusan pengguna 13 Nov 2026); setiap angka tetap memeriksa izinnya.
            'business_stats' => in_array($role, ['owner', 'super-admin'], true) ? $this->businessStats($user) : [],
        ];
    }

    /**
     * Angka bisnis bulan ini untuk Pemilik. Setiap angka memeriksa izinnya sendiri. Definisi (usulan, belum ada di Decision Log):
     * nilai rata-rata pesanan = pendapatan kotor bulan ini dibagi pesanan dibayar bulan ini (RevenueService); pelanggan baru = akun tanpa role yang dibuat
     * bulan ini; rasio batal = dari pesanan yang dibayar bulan ini (`paid_at`), berapa persen yang kini berstatus dibatalkan (`cancelled_at`).
     *
     * @return list<array{key: string, label: string, value: string, foot: string}>
     */
    private function businessStats(User $user): array
    {
        $rupiah = fn (int $n) => 'Rp'.number_format($n, 0, ',', '.');
        $range = $this->monthRange(0);
        $tiles = [];

        $paidCount = Order::whereBetween('paid_at', $range)->count();

        if ($user->can('payments.view')) {
            // Pendapatan kotor dibagi pesanan dibayar, kelompok yang sama dengan KPI pendapatan dan rasio batal (RevenueService).
            $revenue = app(RevenueService::class);
            $previousRange = $this->monthRange(1);
            $previousCount = Order::whereBetween('paid_at', $previousRange)->count();
            $tiles[] = [
                'key' => 'avg_order', 'label' => 'Nilai rata-rata pesanan',
                'value' => $paidCount > 0 ? $rupiah((int) round($revenue->gross($range) / $paidCount)) : '-',
                'foot' => $previousCount > 0 ? 'Bulan lalu '.$rupiah((int) round($revenue->gross($previousRange) / $previousCount)) : 'Bulan lalu belum ada pembanding',
            ];
        }

        if ($user->can('customers.view')) {
            $tiles[] = [
                'key' => 'new_customers', 'label' => 'Pelanggan baru',
                'value' => (string) User::whereDoesntHave('roles')->whereBetween('created_at', $range)->count(),
                'foot' => 'Dari '.User::whereDoesntHave('roles')->count().' akun pelanggan',
            ];
        }

        if ($user->can('orders.view')) {
            // Satu kelompok: pesanan yang dibayar bulan ini (paid_at, apa pun status bayarnya sekarang), sama dengan KPI "Pesanan bulan ini".
            // Pembatalan pesanan terbayar mengubah payment_status menjadi refund_pending, jadi tidak boleh memakai filter 'paid' di sini.
            $cohort = Order::whereBetween('paid_at', $range);
            $cohortCount = (clone $cohort)->count();
            $cancelled = (clone $cohort)->whereNotNull('cancelled_at')->count();
            $tiles[] = [
                'key' => 'cancel_rate', 'label' => 'Rasio pembatalan',
                'value' => $cohortCount > 0 ? number_format($cancelled / $cohortCount * 100, 1, ',', '').'%' : '-',
                'foot' => $cancelled.' dari '.$cohortCount.' pesanan dibayar bulan ini dibatalkan',
            ];
        }

        return $tiles;
    }

    // ---------- perhatian ----------

    /**
     * @return list<array{key: string, text: string, tone: string, count: int, to: string, covered: int, overlap: bool}>
     */
    private function attention(User $user): array
    {
        $items = [];
        $add = function (string $key, int $count, string $text, string $tone, string $to) use (&$items) {
            if ($count > 0) {
                $items[] = ['key' => $key, 'text' => str_replace(':n', (string) $count, $text), 'tone' => $tone, 'count' => $count, 'to' => $to, 'covered' => 0, 'overlap' => false];
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

        // Refund barang bermasalah dan retur (D-031, D-097): CS memproses dan mencatat transfer, Pemilik memutuskan.
        if ($user->can('refunds.process')) {
            $add('refund_process', Refund::whereIn('status', [Refund::SUBMITTED, Refund::AWAITING_EVIDENCE])->count(), ':n pengajuan refund perlu diproses (hubungi pelanggan atau periksa video)', 'warn', 'AdminRefunds');
            $add('refund_payout', Refund::where('status', Refund::APPROVED)->count(), ':n refund disetujui, menunggu diselesaikan (transfer, kirim ulang, atau voucher)', 'warn', 'AdminRefunds');
        }

        if ($user->can('refunds.decide')) {
            $add('refund_decide', Refund::where('status', Refund::UNDER_REVIEW)->count(), ':n refund menunggu keputusan Anda', 'warn', 'AdminRefunds');
        }

        // Draf konten AI Marketing yang menunggu keputusan (D-062, D-079): Pemilik dan Super Admin memutuskan.
        if ($user->can('marketing_drafts.approve')) {
            $add('draft_review', MarketingDraft::where('status', MarketingDraft::IN_REVIEW)->count(), ':n draf konten menunggu keputusan Anda', 'warn', 'AdminMarketingDrafts');
        }

        if ($user->can('refunds.view')) {
            $add('refund_overdue', Refund::whereIn('status', [...Refund::OPEN, Refund::APPROVED])->where('due_at', '<', $this->now)->count(), ':n refund lewat batas proses', 'bad', 'AdminRefunds');
        }

        // Pemilik hanya memantau (menandai dikembalikan tetap hak `orders.cancel`), tetapi uang pelanggan ini harus terlihat olehnya.
        if ($user->canAny(['orders.cancel', 'orders.cancel_final']) && $user->can('payments.view')) {
            $add('late_payment_refund', Order::latePaymentClosed()->count(), ':n dana masuk untuk pesanan yang sudah ditutup perlu dikembalikan', 'bad', 'AdminPayments');
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
            $add('stock_low', $levels->filter(fn ($s) => $s->available > 0 && $s->available < $s->threshold)->count(), ':n produk stok menipis', 'warn', 'AdminStock');
        }

        if ($user->can('settings.manage')) {
            $health = $this->health();
            $add('system_issue', $health['system_bad'], ':n pemeriksaan sistem bermasalah (penjadwal, antrean, disk, atau konfigurasi)', 'bad', 'AdminSettings');
            $add('security_alert', $health['security']['count'], ':n peringatan keamanan (login gagal, perangkat baru, atau MFA)', $health['security']['bad'] ? 'bad' : 'warn', 'AdminSettings');
            $add('stuck_orders', $health['stuck']['count'], ':n pesanan macet perlu dicek (dibayar tanpa diproses, resi, atau dana masuk)', $health['stuck']['bad'] ? 'bad' : 'warn', 'AdminSettings');
            $add('integration_issue', collect($health['integrations'])->where('status', 'bad')->count(), ':n integrasi bermasalah (pembayaran, pengiriman, atau layanan lain)', 'bad', 'AdminSettings');
        }

        if ($user->can('users.manage')) {
            $add('staff_no_mfa', $this->staff()->whereNull('mfa_totp_confirmed_at')->count(), ':n akun staf belum memakai MFA', 'warn', 'AdminUsers');
        }

        // Draf konten AI Marketing (D-062): Pemilik memutuskan, Marketing merevisi dan memposting.
        $drafts = $user->canAny(['marketing_drafts.approve', 'marketing_drafts.manage']) ? app(MarketingReminderService::class)->counts() : ['in_review' => 0, 'stale' => 0, 'rejected' => 0, 'due' => 0];
        if ($user->can('marketing_drafts.approve')) {
            $add('draft_review', $drafts['in_review'], ':n draf konten menunggu keputusan Anda', 'warn', 'AdminMarketingDrafts');
        }
        if ($user->can('marketing_drafts.manage')) {
            $add('draft_rejected', $drafts['rejected'], ':n draf konten ditolak, perlu direvisi', 'warn', 'AdminMarketingDrafts');
            $add('draft_due', $drafts['due'], ':n draf disetujui, jadwalnya besok atau sudah lewat, belum ditandai diposting', 'warn', 'AdminMarketingDrafts');
        }

        if ($user->can('vouchers.manage')) {
            $add('voucher_ending', Voucher::where('is_active', true)->whereBetween('ends_at', [$this->now, $this->now->addDays(7)])->count(), ':n voucher berakhir dalam 7 hari', 'warn', 'AdminVouchers');
        }

        if ($user->can('content.manage')) {
            $add('content_draft', PromoContent::where('is_published', false)->count(), ':n konten promo masih draf', 'warn', 'AdminContents');
        }

        // covered: berapa dari hitungan ini sudah terhitung di peringatan lain yang tampil bagi akun yang sama. KPI total menjumlah
        // count - covered, jadi tidak dua kali. overlap: seluruhnya sudah terhitung di tempat lain.
        $keys = array_column($items, 'key');
        foreach ($items as &$item) {
            $item['covered'] = min($item['count'], $this->covered($item['key'], $keys));
            $item['overlap'] = $item['covered'] >= $item['count'];
        }
        unset($item);

        return $items;
    }

    /**
     * Hitungan sebuah peringatan yang sudah ada di peringatan tahap lain milik akun yang sama. Dihitung per baris (bukan per ada-tidaknya
     * pasangan), jadi akun yang hanya melihat sebagian tahap tetap menghitung baris yang tidak tampil di tahap mana pun.
     *
     * @param  list<string>  $keys  kunci peringatan yang tampil untuk akun ini
     */
    private function covered(string $key, array $keys): int
    {
        $shown = fn (string $k) => in_array($k, $keys, true);

        return match ($key) {
            // Resi menunggu (diproses, tanpa resi) selalu bagian dari antrean kemas (dibayar atau diproses).
            'awaiting_waybill' => $shown('to_pack') ? $this->awaitingWaybillCount() : 0,
            'cancel_overdue' => $this->coveredOverdueCancellations($shown),
            'refund_overdue' => $this->coveredOverdueRefunds($shown),
            // Pesanan macet (Super Admin): tiga dari lima pemeriksaannya adalah himpunan bagian peringatan lain.
            'stuck_orders' => (int) collect($this->health()['stuck']['by_key'])->only(array_filter([
                $shown('to_pack') ? 'paid_unprocessed' : null,
                $shown('awaiting_waybill') ? 'no_waybill' : null,
                $shown('late_payment_refund') ? 'late_payment' : null,
            ]))->sum(),
            default => 0,
        };
    }

    /** Pengajuan pembatalan yang lewat batas dan tahapnya tampil di antrean akun ini (validasi, ACC, ACC Pemilik, atau tandai dana). */
    private function coveredOverdueCancellations(callable $shown): int
    {
        $statuses = array_values(array_filter([
            $shown('cancel_validate') ? OrderCancellationRequest::SUBMITTED : null,
            $shown('cancel_acc') ? OrderCancellationRequest::VALIDATED : null,
            $shown('cancel_final') ? OrderCancellationRequest::STORE_APPROVED : null,
        ]));
        $refundMark = $shown('refund_mark');

        if ($statuses === [] && ! $refundMark) {
            return 0;
        }

        return $this->overdueQuery()->where(function (Builder $q) use ($statuses, $refundMark) {
            $q->whereIn('status', $statuses);

            if ($refundMark) {
                $q->orWhere(fn (Builder $r) => $r->where('status', OrderCancellationRequest::APPROVED)->whereHas('order', fn ($o) => $o->where('payment_status', 'refund_pending')));
            }
        })->count();
    }

    /** Refund yang lewat batas dan tahapnya tampil di antrean akun ini (diproses, diselesaikan, atau diputuskan). */
    private function coveredOverdueRefunds(callable $shown): int
    {
        $statuses = [
            ...($shown('refund_process') ? [Refund::SUBMITTED, Refund::AWAITING_EVIDENCE] : []),
            ...($shown('refund_payout') ? [Refund::APPROVED] : []),
            ...($shown('refund_decide') ? [Refund::UNDER_REVIEW] : []),
        ];

        return $statuses === [] ? 0 : Refund::whereIn('status', $statuses)->where('due_at', '<', $this->now)->count();
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
     * @param  list<array{key: string, count: int, covered: int}>  $attention
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
                'key' => $key, 'label' => 'Perlu tindakan', 'value' => $total = collect($attention)->sum(fn (array $a) => $a['count'] - $a['covered']), 'format' => 'number', 'icon' => 'alert', 'tone' => 'bad', 'delta' => null,
                'foot' => $total > 0 ? 'Ketuk untuk melihat rinciannya' : 'Tidak ada yang perlu ditindaklanjuti', 'spark' => null, 'action' => 'attention',
            ],
            'to_process' => $user->can('orders.update_status') ? [
                'key' => $key, 'label' => 'Perlu diproses', 'value' => $count('to_process'), 'format' => 'number', 'icon' => 'receipt', 'tone' => 'bad', 'delta' => null,
                'foot' => 'Sudah dibayar, belum diproses', 'spark' => null,
            ] : null,
            'low_stock' => $user->can('stock.view') ? [
                'key' => $key, 'label' => 'Stok menipis', 'value' => $count('stock_low') + $count('stock_out'), 'format' => 'number', 'icon' => 'warehouse', 'tone' => 'warn', 'delta' => null,
                'foot' => $count('stock_out').' habis, batas bawaan '.($this->lowStock ??= self::lowStockLimit()), 'spark' => null,
            ] : null,
            'vouchers_active' => $user->can('vouchers.view') ? [
                'key' => $key, 'label' => 'Voucher aktif', 'value' => $this->activeVouchers()->count(), 'format' => 'number', 'icon' => 'ticket', 'tone' => 'neutral', 'delta' => null,
                'foot' => $this->activeVouchers()->whereBetween('ends_at', [$this->now, $this->now->addDays(7)])->count().' berakhir minggu ini', 'spark' => null,
            ] : null,
            'cancel_validate_pending' => $user->can('orders.cancel_request') ? [
                'key' => $key, 'label' => 'Menunggu validasi', 'value' => $this->cancelCount(OrderCancellationRequest::SUBMITTED), 'format' => 'number', 'icon' => 'x', 'tone' => 'bad', 'delta' => null,
                'foot' => 'Pengajuan dari pelanggan', 'spark' => null,
            ] : null,
            'refund_pending' => $user->can('refunds.process') ? [
                'key' => $key, 'label' => 'Refund perlu diproses', 'value' => $this->refundToProcessQuery()->count(), 'format' => 'number', 'icon' => 'undo', 'tone' => 'bad', 'delta' => null,
                'foot' => Refund::where('status', Refund::APPROVED)->count().' dana menunggu dikembalikan', 'spark' => null,
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
        $today = $this->local->startOfDay();
        $yesterday = Order::whereBetween('created_at', $this->dayRange($today->subDay()))->count();
        $value = Order::where('created_at', '>=', $this->utc($today))->count();

        return [
            'key' => 'orders_today', 'label' => 'Pesanan hari ini', 'value' => $value, 'format' => 'number', 'icon' => 'receipt', 'tone' => 'warn',
            'delta' => $this->delta($value, $yesterday), 'foot' => $yesterday > 0 ? 'Dibanding kemarin' : 'Kemarin belum ada pesanan',
            'spark' => collect(range(6, 0))->map(fn (int $i) => Order::whereBetween('created_at', $this->dayRange($today->subDays($i)))->count())->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function revenueKpi(): array
    {
        $revenue = app(RevenueService::class);
        $rupiah = fn (int $n) => ($n < 0 ? '-' : '').'Rp'.number_format(abs($n), 0, ',', '.');
        $gross = $revenue->gross($this->monthRange(0));
        $refunded = $revenue->refunded($this->monthRange(0));
        $value = $gross - $refunded;
        $previous = $revenue->net($this->monthRange(1));

        return [
            'key' => 'revenue_month', 'label' => 'Pendapatan bersih bulan ini', 'value' => $value, 'format' => 'rupiah', 'icon' => 'wallet', 'tone' => 'warn',
            'delta' => $this->delta($value, $previous), 'foot' => 'Kotor '.$rupiah($gross).', dikembalikan '.$rupiah($refunded), 'spark' => null,
        ];
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
                ['key' => 'revenue', 'name' => 'Pendapatan kotor', 'tone' => 'primary', 'values' => $this->monthly(fn ($r) => app(RevenueService::class)->gross($r))],
                ['key' => 'refunded', 'name' => 'Dana dikembalikan', 'tone' => 'secondary', 'values' => $this->monthly(fn ($r) => app(RevenueService::class)->refunded($r))],
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
        return array_map(fn (int $i) => self::MONTHS[$this->local->startOfMonth()->subMonths($i)->month - 1], range(9, 0));
    }

    /** @return list<string> */
    private function dayLabels(): array
    {
        return array_map(fn (int $i) => self::DAYS[$this->local->startOfDay()->subDays($i)->dayOfWeekIso - 1], range(6, 0));
    }

    /** @return list<string> */
    private function weekLabels(): array
    {
        return array_map(fn (int $i) => 'M'.(8 - $i), range(7, 0));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function monthRange(int $back): array
    {
        $start = $this->local->startOfMonth()->subMonths($back);

        return [$this->utc($start), $this->utc($start->endOfMonth())];
    }

    /** @return list<int> */
    private function monthly(callable $count): array
    {
        return array_map(fn (int $i) => $count($this->monthRange($i)), range(9, 0));
    }

    /** @return list<int> */
    private function daily(callable $count): array
    {
        return array_map(fn (int $i) => $count($this->dayRange($this->local->subDays($i))), range(6, 0));
    }

    /** @return list<int> */
    private function weekly(callable $count): array
    {
        return array_map(fn (int $i) => $count([$this->utc($this->local->startOfWeek()->subWeeks($i)), $this->utc($this->local->startOfWeek()->subWeeks($i)->endOfWeek())]), range(7, 0));
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
                $shipped = Shipment::where('shipped_at', '>=', $this->utc($this->local->startOfDay()))->count();
                $open = Order::whereIn('status', [Order::PAID, Order::PROCESSING])->count();

                return $this->gaugeData('Pengiriman hari ini', 'Terkirim dari yang perlu dikirim', $shipped + $open > 0 ? (int) round($shipped / ($shipped + $open) * 100) : null, [['Terkirim hari ini', (string) $shipped], ['Belum dikirim', (string) $open]], $open.' pesanan belum dikirim');
            case 'packed_today':
                if (! $user->can('orders.view_fulfillment')) {
                    return null;
                }
                $packed = OrderStatusHistory::where('status_type', 'order')->where('to_status', Order::PROCESSING)->where('created_at', '>=', $this->utc($this->local->startOfDay()))->count();
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
            ])->all(), 'Belum ada aktivitas.', ['label' => 'Buka audit log', 'to' => 'AdminAudit']) : null,
            'attention' => $this->listData($key, 'Perlu perhatian', null, collect($attention)->take(3)->map(fn (array $a) => [
                'kind' => 'alert', 'icon' => $a['tone'] === 'bad' ? 'alert' : 'clock', 'tone' => $a['tone'], 'title' => $a['text'], 'subtitle' => null, 'to' => $a['to'],
            ])->all(), 'Tidak ada yang perlu ditindaklanjuti.'),
            'integrations' => $user->can('settings.manage') ? $this->integrationList($key) : null,
            'top_products' => $user->canAny(['orders.view', 'reports.view_summary']) ? $this->topProducts($user) : null,
            'cancel_final_queue' => $user->can('orders.cancel_final') ? $this->cancelQueue($key, 'Menunggu keputusan Anda', OrderCancellationRequest::STORE_APPROVED, 'ACC Pemilik') : null,
            'refund_decide_queue' => $user->can('refunds.decide') ? $this->refundDecideQueue($key) : null,
            'draft_review_queue' => $user->can('marketing_drafts.approve') ? $this->draftReviewQueue($key) : null,
            'cancel_acc_queue' => $user->can('orders.cancel') ? $this->cancelQueue($key, 'Antrean ACC pembatalan', OrderCancellationRequest::VALIDATED, 'Divalidasi CS') : null,
            'cancel_validate_queue' => $user->can('orders.cancel_request') ? $this->cancelQueue($key, 'Antrean validasi', OrderCancellationRequest::SUBMITTED, 'Baru') : null,
            'refund_queue' => $user->can('refunds.process') ? $this->refundQueue($key) : null,
            'followup_due' => $user->can('followups.manage') ? $this->followUpList($key) : null,
            'shipment_problems' => $user->can('shipments.view') && $user->can('followups.manage') ? $this->shipmentProblemList($key) : null,
            'low_reviews' => $user->can('product_reviews.view') && $user->can('followups.manage') ? $this->lowReviewList($key) : null,
            'cancel_overdue_list' => $user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final']) ? $this->listData($key, 'Lewat batas proses', null, $this->overdueQuery()->with('order:id,order_number,total')->orderBy('due_at')->limit(3)->get()->map(fn (OrderCancellationRequest $r) => [
                'kind' => 'alert', 'icon' => 'clock', 'tone' => 'bad', 'title' => $r->request_number.' lewat batas', 'subtitle' => 'Pesanan '.$r->order->order_number.'. Pantau sampai selesai.', 'to' => 'AdminCancellations',
            ])->all(), 'Tidak ada pengajuan yang lewat batas.') : null,
            'orders_recent' => $user->can('orders.view') ? $this->listData($key, 'Pesanan terbaru', null, Order::latest('id')->limit(3)->get()->map(fn (Order $o) => [
                'icon' => 'receipt', 'tone' => 'neutral', 'title' => $o->order_number.' · '.$o->customer_name, 'subtitle' => strtoupper($o->shipping_courier).' '.strtoupper($o->shipping_service),
                'status' => $o->status, 'to' => 'AdminOrders',
            ])->all(), 'Belum ada pesanan.') : null,
            'pack_queue' => $user->can('orders.view_fulfillment') ? $this->listData($key, 'Antrean kemas', 'Tanpa harga dan data pembayaran', Order::with('items')->whereIn('status', [Order::PAID, Order::PROCESSING])->orderBy('paid_at')->limit(3)->get()->map(fn (Order $o) => [
                'icon' => 'box', 'tone' => 'warn', 'title' => $o->order_number, 'subtitle' => $o->items->map(fn ($i) => $i->quantity.' × '.$i->product_name_snapshot)->implode(', '), 'status' => $o->status, 'to' => 'AdminFulfillment',
            ])->all(), 'Tidak ada pesanan yang perlu dikemas.', ['label' => 'Lihat semua antrean kemas', 'to' => 'AdminFulfillment']) : null,
            'stock_alerts' => $user->can('stock.view') ? $this->listData($key, 'Stok menipis', null, $this->stockLevels()->filter(fn ($s) => $s->available <= 0 || $s->available < $s->threshold)->sortBy('available')->take(3)->map(fn ($s) => [
                'kind' => 'alert', 'icon' => $s->available <= 0 ? 'alert' : 'warehouse', 'tone' => $s->available <= 0 ? 'bad' : 'warn',
                'title' => ($s->available <= 0 ? 'Habis: ' : 'Tipis: ').$s->product, 'subtitle' => 'Tersedia '.max($s->available, 0).' · '.$s->sku, 'to' => 'AdminStock',
            ])->values()->all(), 'Semua stok aman.', ['label' => 'Lihat semua stok menipis', 'to' => 'AdminStock', 'query' => ['level' => 'alert']]) : null,
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
    /**
     * @param  array{label: string, to: string, query?: array<string, string>}|null  $more  tautan "Lihat semua" di bawah daftar
     */
    private function listData(string $key, string $title, ?string $subtitle, array $rows, string $empty, ?array $more = null): array
    {
        return ['key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'rows' => $rows, 'empty' => $empty, 'more' => $more];
    }

    /** Refund yang menunggu tindakan CS: baru, menunggu bukti, atau disetujui dan dananya belum ditransfer. */
    /** @return Builder<Refund> */
    private function refundToProcessQuery(): Builder
    {
        return Refund::whereIn('status', [Refund::SUBMITTED, Refund::AWAITING_EVIDENCE, Refund::APPROVED]);
    }

    /** Status paket Biteship yang bermasalah dan label tampilannya (sama dengan yang diberitahukan ke staf, D-082). */
    private const SHIPMENT_PROBLEMS = [
        'returned' => 'Dikembalikan', 'return_in_transit' => 'Dalam pengembalian', 'rejected' => 'Ditolak penerima', 'cancelled' => 'Dibatalkan kurir',
        'courier_not_found' => 'Kurir tidak ditemukan', 'on_hold' => 'Ditahan', 'disposed' => 'Dimusnahkan',
    ];

    /** Paket bermasalah 14 hari terakhir, terbaru dulu, supaya CS bisa menghubungi pelanggan (D-107). */
    private function shipmentProblemList(string $key): array
    {
        $shipments = Shipment::whereIn('status', array_keys(self::SHIPMENT_PROBLEMS))->where('updated_at', '>=', $this->now->subDays(14))->orderByDesc('updated_at')->limit(3)->get(['order_id', 'status']);
        $orders = Order::whereIn('id', $shipments->pluck('order_id'))->get(['id', 'order_number', 'customer_name'])->keyBy('id');

        $rows = $shipments->filter(fn (Shipment $s) => $orders->has($s->order_id))->map(fn (Shipment $s) => [
            'icon' => 'truck', 'tone' => 'bad', 'title' => $orders[$s->order_id]->order_number.' · '.$orders[$s->order_id]->customer_name, 'subtitle' => 'Hubungi pelanggan',
            'chip' => ['text' => self::SHIPMENT_PROBLEMS[$s->status], 'tone' => 'bad'], 'to' => 'AdminOrders', 'query' => ['cari' => $orders[$s->order_id]->order_number],
        ])->values()->all();

        return $this->listData($key, 'Pengiriman bermasalah', '14 hari terakhir', $rows, 'Tidak ada paket bermasalah.');
    }

    /** Ulasan produk berbintang 1 atau 2 dalam 14 hari terakhir; menuju pesanan asalnya bila ada (D-107). */
    private function lowReviewList(string $key): array
    {
        $reviews = ProductReview::with('product:id,name')->where('rating', '<=', 2)->where('created_at', '>=', $this->now->subDays(14))->latest('id')->limit(3)->get();
        $numbers = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->whereIn('order_items.id', $reviews->pluck('order_item_id')->filter())->pluck('orders.order_number', 'order_items.id');

        $rows = $reviews->map(fn (ProductReview $r) => [
            'icon' => 'star', 'tone' => 'warn', 'title' => data_get($r, 'product.name', 'Produk'), 'subtitle' => $r->author_name.($numbers->has($r->order_item_id) ? ' · '.$numbers[$r->order_item_id] : ''),
            'chip' => ['text' => '★ '.$r->rating, 'tone' => 'warn'],
            'to' => $numbers->has($r->order_item_id) ? 'AdminOrders' : 'AdminProductReviews', 'query' => $numbers->has($r->order_item_id) ? ['cari' => $numbers[$r->order_item_id]] : null,
        ])->all();

        return $this->listData($key, 'Ulasan bintang rendah', '14 hari terakhir', $rows, 'Tidak ada ulasan bintang rendah.');
    }

    /** Tindak lanjut CS yang belum selesai, paling mendesak dulu. Yang tanggalnya sudah lewat ditandai merah. */
    private function followUpList(string $key): array
    {
        $kinds = [
            Order::class => ['AdminOrders', 'Pesanan', fn ($o) => $o->order_number],
            Refund::class => ['AdminRefunds', 'Refund', fn ($r) => $r->refund_number],
            OrderCancellationRequest::class => ['AdminCancellations', 'Pembatalan', fn ($r) => $r->request_number],
        ];

        $rows = FollowUp::with('followable')->whereNull('done_at')->orderBy('due_on')->orderBy('id')->limit(3)->get()
            ->filter(fn (FollowUp $f) => $f->followable !== null && isset($kinds[$f->followable::class]))
            ->map(function (FollowUp $f) use ($kinds) {
                [$route, $label, $number] = $kinds[$f->followable::class];
                $overdue = $f->isOverdue();
                $due = $f->due_on->isToday() ? 'Hari ini' : ($overdue ? 'Lewat' : $f->due_on->format('d M'));

                return [
                    'icon' => 'clock', 'tone' => $overdue ? 'bad' : 'warn', 'title' => $f->note, 'subtitle' => $label.' '.$number($f->followable),
                    'chip' => ['text' => $due, 'tone' => $overdue ? 'bad' : 'warn'], 'to' => $route, 'query' => ['cari' => $number($f->followable)],
                ];
            })->values()->all();

        return $this->listData($key, 'Tindak lanjut', 'Pengingat dari CS', $rows, 'Tidak ada tindak lanjut.');
    }

    /** @return array<string, mixed> */
    private function refundQueue(string $key): array
    {
        $labels = [Refund::SUBMITTED => 'Baru', Refund::AWAITING_EVIDENCE => 'Menunggu bukti', Refund::APPROVED => 'Transfer dana'];
        $rows = $this->refundToProcessQuery()->with('order:id,order_number')->orderBy('due_at')->limit(3)->get()->map(fn (Refund $r) => [
            'icon' => 'undo', 'tone' => $r->isOverdue() ? 'bad' : 'warn', 'title' => $r->refund_number, 'subtitle' => 'Pesanan '.$r->order->order_number.($r->isOverdue() ? ' · Lewat batas' : ''),
            'chip' => ['text' => $labels[$r->status], 'tone' => $r->isOverdue() ? 'bad' : 'warn'], 'to' => 'AdminRefunds',
        ])->all();

        return $this->listData($key, 'Antrean refund', null, $rows, 'Tidak ada refund yang menunggu.');
    }

    /** Refund yang menunggu keputusan Pemilik (`refunds.decide`), yang paling mendekati batas dulu. */
    private function refundDecideQueue(string $key): array
    {
        $rows = Refund::with('order:id,order_number')->where('status', Refund::UNDER_REVIEW)->orderBy('due_at')->limit(3)->get()->map(fn (Refund $r) => [
            'icon' => 'undo', 'tone' => $r->isOverdue() ? 'bad' : 'warn', 'title' => $r->refund_number, 'subtitle' => 'Pesanan '.$r->order->order_number.($r->isOverdue() ? ' · Lewat batas' : ''),
            'chip' => ['text' => 'Keputusan Anda', 'tone' => $r->isOverdue() ? 'bad' : 'warn'], 'to' => 'AdminRefunds',
        ])->all();

        return $this->listData($key, 'Refund menunggu keputusan', null, $rows, 'Tidak ada refund yang menunggu keputusan.', ['label' => 'Lihat semua refund', 'to' => 'AdminRefunds']);
    }

    /** Draf konten yang sudah diajukan Marketing dan belum diputuskan (`marketing_drafts.approve`), yang paling lama menunggu dulu. */
    private function draftReviewQueue(string $key): array
    {
        $rows = MarketingDraft::with('creator:id,name')->where('status', MarketingDraft::IN_REVIEW)->orderBy('submitted_at')->limit(3)->get()->map(fn (MarketingDraft $d) => [
            'icon' => 'sparkles', 'tone' => 'warn', 'title' => $d->title, 'subtitle' => (MarketingDraft::TYPES[$d->type] ?? $d->type).($d->creator ? ' · '.$d->creator->name : ''),
            'chip' => ['text' => 'Ditinjau', 'tone' => 'warn'], 'at' => $d->submitted_at?->toIso8601String(), 'to' => 'AdminMarketingDrafts',
        ])->all();

        return $this->listData($key, 'Draf konten menunggu keputusan', 'Dari tim Marketing', $rows, 'Tidak ada draf yang menunggu.', ['label' => 'Lihat semua draf', 'to' => 'AdminMarketingDrafts']);
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
        })->all(), 'Belum ada penjualan dalam 30 hari terakhir.', $user->canAny(['reports.view', 'reports.view_summary']) ? ['label' => 'Buka laporan', 'to' => 'AdminReports'] : null);
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

    // ---------- integrasi ----------

    /** @return list<array{key: string, title: string, status: string, issues: list<string>}> */
    private function integrations(): array
    {
        return $this->health()['integrations'];
    }

    /**
     * Ringkasan pemeriksaan untuk Ringkasan Super Admin, disimpan `dashboard.health_cache_seconds` detik (60) supaya tidak dihitung
     * ulang di setiap muat. Bukan data per pengguna: hanya dibaca akun dengan izin settings.manage. Halaman Pengaturan tidak memakai
     * cache ini. Tombol "Cek ke API" dan "Tandai sudah ditangani" membuangnya (HEALTH_CACHE_KEY) supaya hasilnya langsung terlihat.
     *
     * @return array{system_bad: int, security: array{count: int, bad: bool}, stuck: array{count: int, bad: bool, by_key: array<string, int>}, integrations: list<array{key: string, title: string, status: string, issues: list<string>}>}
     */
    private function health(): array
    {
        $ttl = (int) config('dashboard.health_cache_seconds', 60);

        return $this->health ??= $ttl > 0 ? Cache::remember(self::HEALTH_CACHE_KEY, $ttl, fn () => $this->computeHealth()) : $this->computeHealth();
    }

    /** @return array{system_bad: int, security: array{count: int, bad: bool}, stuck: array{count: int, bad: bool, by_key: array<string, int>}, integrations: list<array{key: string, title: string, status: string, issues: list<string>}>} */
    private function computeHealth(): array
    {
        // Staf tanpa MFA punya peringatan sendiri, jadi tidak dihitung dua kali di keamanan.
        $security = collect(app(SecurityHealthService::class)->checks())->where('status', '!=', 'ok')->reject(fn (array $c) => $c['key'] === 'staff_no_mfa');
        $stuck = collect(app(StuckOrderService::class)->checks())->where('status', '!=', 'ok');

        $integrations = app(IntegrationHealthService::class);
        $others = collect($integrations->services());

        // Layanan pendukung diringkas jadi satu baris supaya kartu dashboard tetap pendek; rinciannya di Pengaturan.
        $summary = [
            'key' => 'services', 'title' => 'Layanan lain',
            'status' => $others->contains('status', 'bad') ? 'bad' : ($others->contains('status', 'warn') ? 'warn' : 'ok'),
            'issues' => $others->where('status', '!=', 'ok')->map(fn (array $s) => $s['title'].': '.implode('; ', $s['issues']))->values()->all(),
        ];

        return [
            'system_bad' => collect(app(SystemHealthService::class)->checks())->where('status', 'bad')->count(),
            'security' => ['count' => $security->count(), 'bad' => $security->contains('status', 'bad')],
            'stuck' => ['count' => (int) $stuck->sum('count'), 'bad' => $stuck->contains('status', 'bad'), 'by_key' => $stuck->pluck('count', 'key')->all()],
            // Hanya kolom yang dipakai Ringkasan, supaya isi cache kecil dan mudah diserialisasi.
            'integrations' => [
                ...collect($integrations->overview())->map(fn (array $i) => ['key' => $i['key'], 'title' => $i['title'], 'status' => $i['status'], 'issues' => $i['issues']])->all(),
                $summary,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function integrationList(string $key): array
    {
        $chips = ['bad' => 'Bermasalah', 'warn' => 'Periksa', 'ok' => 'Siap'];

        $rows = collect($this->integrations())->map(fn (array $i) => [
            'kind' => $i['status'] === 'ok' ? null : 'alert', 'wrap' => true, 'icon' => ['midtrans' => 'wallet', 'biteship' => 'truck'][$i['key']] ?? 'sparkles', 'tone' => $i['status'],
            'title' => $i['title'],
            'subtitle' => $i['issues'] ? implode('. ', $i['issues']).'.' : ($i['key'] === 'services' ? 'Email, OTP WhatsApp, Turnstile, dan Claude siap.' : 'Kunci, mode, dan webhook siap.'),
            'chip' => ['text' => $chips[$i['status']], 'tone' => $i['status']], 'to' => 'AdminSettings',
        ])->all();

        return $this->listData($key, 'Integrasi', 'Dari konfigurasi, riwayat webhook, dan cek API terakhir', $rows, 'Belum ada integrasi.');
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
            ->groupBy('product_variants.id', 'product_variants.sku', 'products.name', 'product_variants.low_stock_threshold')
            // threshold: batas varian bila diisi, jika tidak batas bawaan dari Pengaturan.
            ->selectRaw('product_variants.id, product_variants.sku, products.name as product, COALESCE(SUM(inventory_stocks.quantity - inventory_stocks.reserved), 0) as available, COALESCE(product_variants.low_stock_threshold, ?) as threshold', [$this->lowStock ??= self::lowStockLimit()])
            ->get();
    }
}
