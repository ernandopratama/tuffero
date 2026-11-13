<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pesanan yang macet di tengah alur, untuk Super Admin. Hanya membaca dan menghitung: tidak mengubah status, stok, atau dana
 * (aturan pesanan, stok, dan refund tetap di OrderStatusService, InventoryService, dan CancellationService).
 *
 * PROPOSED: batas waktu di bawah belum diputuskan tim bisnis. Angka ini hanya untuk menandai pesanan yang patut dilihat,
 * bukan SLA. Ubah di sini setelah disetujui.
 */
class StuckOrderService
{
    /** Dibayar tetapi belum diproses: peringatan setelah 24 jam, masalah setelah 72 jam. */
    public const PAID_WARN_HOURS = 24;

    public const PAID_BAD_HOURS = 72;

    /** Pembayaran kedaluwarsa yang belum ditutup. Penjadwal menutupnya tiap menit, jadi lewat 10 menit berarti penjadwal bermasalah. */
    public const EXPIRED_OPEN_MINUTES = 10;

    /** Diproses tanpa nomor resi: peringatan setelah 48 jam, masalah setelah 120 jam. */
    public const NO_WAYBILL_WARN_HOURS = 48;

    public const NO_WAYBILL_BAD_HOURS = 120;

    /** Dikirim tetapi belum sampai: peringatan setelah 14 hari. */
    public const SHIPPED_DAYS = 14;

    private CarbonImmutable $now;

    public function __construct()
    {
        $this->now = CarbonImmutable::now();
    }

    /** @return list<array{key: string, label: string, status: string, count: int, detail: string, to: string}> */
    public function checks(): array
    {
        $paid = fn (int $hours) => Order::where('status', Order::PAID)->where('paid_at', '<', $this->now->subHours($hours));
        $noWaybill = fn (int $hours) => Order::where('status', Order::PROCESSING)
            ->whereDoesntHave('shipment', fn ($q) => $q->whereNotNull('waybill_id'))
            ->whereHas('histories', fn ($q) => $q->where('status_type', 'order')->where('to_status', Order::PROCESSING)->where('created_at', '<', $this->now->subHours($hours)));

        return [
            $this->check('paid_unprocessed', 'Dibayar, belum diproses', $paid(self::PAID_WARN_HOURS), $paid(self::PAID_BAD_HOURS),
                'pesanan sudah dibayar lebih dari '.self::PAID_WARN_HOURS.' jam dan belum diproses', 'AdminOrders'),
            $this->check('expired_open', 'Batas bayar lewat, belum ditutup',
                Order::where('status', Order::PENDING_PAYMENT)->where('expires_at', '<', $this->now->subMinutes(self::EXPIRED_OPEN_MINUTES)), 'always',
                'pesanan lewat batas bayar tetapi stoknya masih tertahan. Periksa penjadwal', 'AdminOrders'),
            $this->check('late_payment', 'Dana masuk, pesanan sudah ditutup',
                $this->latePayments(), 'always',
                'pesanan ditutup padahal dana sudah diterima Midtrans. Perlu refund manual', 'AdminPayments'),
            $this->check('no_waybill', 'Diproses, belum ada resi', $noWaybill(self::NO_WAYBILL_WARN_HOURS), $noWaybill(self::NO_WAYBILL_BAD_HOURS),
                'pesanan diproses lebih dari '.self::NO_WAYBILL_WARN_HOURS.' jam tanpa nomor resi', 'AdminShipments'),
            $this->check('shipped_stale', 'Dikirim, belum sampai',
                Order::where('status', Order::SHIPPED)->whereHas('shipment', fn ($q) => $q->whereNull('delivered_at')->where('shipped_at', '<', $this->now->subDays(self::SHIPPED_DAYS))), null,
                'pesanan berstatus Dikirim lebih dari '.self::SHIPPED_DAYS.' hari tanpa konfirmasi tiba', 'AdminShipments'),
        ];
    }

    /**
     * Dana yang masuk untuk pesanan yang sudah ditutup (Order::scopeLatePaymentClosed). Hilang dari sini setelah Admin Toko menandainya
     * sudah dikembalikan (CancellationService::markLatePaymentRefunded).
     *
     * @return Builder<Order>
     */
    private function latePayments(): Builder
    {
        return Order::latePaymentClosed();
    }

    /**
     * @param  Builder<Order>  $query
     * @param  Builder<Order>|'always'|null  $severe  Pesanan yang membuat statusnya Bermasalah; 'always' berarti berapa pun jumlahnya; null berarti hanya Periksa.
     * @return array{key: string, label: string, status: string, count: int, detail: string, to: string}
     */
    private function check(string $key, string $label, Builder $query, Builder|string|null $severe, string $detail, string $to): array
    {
        $count = $query->count();

        $status = match (true) {
            $count === 0 => 'ok',
            $severe === 'always' => 'bad',
            $severe instanceof Builder && $severe->exists() => 'bad',
            default => 'warn',
        };

        return ['key' => $key, 'label' => $label, 'status' => $status, 'count' => $count, 'detail' => $count === 0 ? 'Tidak ada' : "$count $detail", 'to' => $to];
    }
}
