<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Refund;

/**
 * Tag repeat order (D-121 butir 6, D-135): nomor WhatsApp yang punya N pesanan selesai (N = `loyalty.repeat_order_count`, bawaan 5).
 * Dihitung per nomor, termasuk pesanan tamu tanpa akun. Pesanan dengan retur atau refund yang disetujui tidak dihitung.
 * Dihitung saat dibaca, tidak disimpan, jadi refund yang disetujui belakangan langsung mengurangi hitungan.
 */
class RepeatOrderService
{
    public static function threshold(): int
    {
        return max(2, OrderReviewService::setting('loyalty.repeat_order_count', 5));
    }

    /**
     * Jumlah pesanan selesai yang dihitung per nomor (nomor sudah dinormalkan seperti di checkout).
     *
     * @param  iterable<string|null>  $phones
     * @return array<string, int>
     */
    public static function counts(iterable $phones): array
    {
        $phones = collect($phones)->filter()->unique()->values();

        if ($phones->isEmpty()) {
            return [];
        }

        return Order::query()
            ->whereIn('customer_phone', $phones)
            ->where('status', Order::COMPLETED)
            ->whereDoesntHave('refunds', fn ($refund) => $refund->whereIn('status', [Refund::APPROVED, Refund::REFUNDED]))
            ->selectRaw('customer_phone, COUNT(*) as aggregate')
            ->groupBy('customer_phone')
            ->pluck('aggregate', 'customer_phone')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, int>  $counts  hasil counts()
     * @return array{completed_orders: int, repeat_order: bool}
     */
    public static function present(?string $phone, array $counts): array
    {
        $completed = $phone ? ($counts[$phone] ?? 0) : 0;

        return ['completed_orders' => $completed, 'repeat_order' => $completed >= self::threshold()];
    }
}
