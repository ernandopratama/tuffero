<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\StoreSite;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tinjauan pesanan oleh Admin Toko (D-121, D-132):
 * - Pesanan Rp1 juta ke atas hanya ditandai; tetap berjalan normal dan cukup ditandai sudah ditinjau.
 * - Pesanan yang menunggu persetujuan disetujui (lalu bisa dibayar lewat Snap seperti biasa) atau ditolak (stok dan voucher dilepas).
 * Tenggat dihitung dalam jam buka toko (StoreSite::OPEN_HOURS, WIB): saat toko tutup dan hari Minggu, waktu berhenti.
 */
class OrderReviewService
{
    public function __construct(private InventoryService $inventory, private VoucherService $vouchers) {}

    public static function setting(string $key, int $default): int
    {
        return (int) Setting::where('key', $key)->value('value') ?: $default;
    }

    /** Tenggat tinjau: `antifraud.review_open_hours` jam buka (bawaan 7 = 1 hari kerja) setelah $from. */
    public function dueAt(CarbonInterface $from): CarbonImmutable
    {
        $minutes = self::setting('antifraud.review_open_hours', 7) * 60;
        $cursor = CarbonImmutable::instance($from)->setTimezone('Asia/Jakarta')->startOfMinute();

        // Paling lama 60 hari dilompati; jam buka yang kosong semua tidak membuat perulangan tanpa akhir.
        for ($day = 0; $day < 60 && $minutes > 0; $day++) {
            $hours = StoreSite::OPEN_HOURS[$cursor->dayOfWeekIso - 1];
            if ($hours !== null) {
                $start = $cursor->max($cursor->setTime($hours[0], 0));
                $end = $cursor->setTime($hours[1], 0);
                $available = $start->lt($end) ? (int) $start->diffInMinutes($end) : 0;
                if ($available >= $minutes) {
                    return $start->addMinutes($minutes)->utc();
                }
                $minutes -= $available;
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        return $cursor->utc();
    }

    public function approve(Order $order, User $actor, ?string $note): Order
    {
        return DB::transaction(function () use ($order, $actor, $note) {
            $locked = $this->lockAwaiting($order);
            $minutes = self::setting('checkout.reservation_minutes', 60);

            $locked->update(['status' => Order::PENDING_PAYMENT, 'expires_at' => now()->addMinutes($minutes), 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_note' => $note]);
            $locked->histories()->create(['status_type' => 'order', 'from_status' => Order::AWAITING_APPROVAL, 'to_status' => Order::PENDING_PAYMENT, 'source' => 'admin', 'actor_user_id' => $actor->id, 'reason' => $note]);
            AuditLog::record('order.approved', $locked, ['total' => (int) $locked->total, 'note' => $note], $actor->id);

            return $locked;
        }, 3);
    }

    public function reject(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $locked = $this->lockAwaiting($order);

            $this->inventory->releaseOrder($locked, 'Ditolak Admin Toko');
            $this->vouchers->release($locked);

            $locked->forceFill(['status' => Order::REJECTED, 'payment_status' => 'cancelled', 'cancelled_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_note' => $reason])->save();
            $locked->histories()->create(['status_type' => 'order', 'from_status' => Order::AWAITING_APPROVAL, 'to_status' => Order::REJECTED, 'source' => 'admin', 'actor_user_id' => $actor->id, 'reason' => mb_substr($reason, 0, 255)]);
            AuditLog::record('order.rejected', $locked, ['total' => (int) $locked->total, 'reason' => $reason], $actor->id);

            return $locked;
        }, 3);
    }

    /** Pesanan bertanda (bukan menunggu persetujuan) sudah diperiksa Admin Toko. */
    public function markReviewed(Order $order, User $actor, ?string $note): Order
    {
        return DB::transaction(function () use ($order, $actor, $note) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->review_due_at === null || $locked->reviewed_at !== null || $locked->status === Order::AWAITING_APPROVAL) {
                throw ValidationException::withMessages(['order' => 'Pesanan ini tidak perlu atau sudah ditinjau.']);
            }

            $locked->update(['reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_note' => $note]);
            AuditLog::record('order.reviewed', $locked, ['total' => (int) $locked->total, 'note' => $note], $actor->id);

            return $locked;
        }, 3);
    }

    private function lockAwaiting(Order $order): Order
    {
        $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== Order::AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['order' => 'Pesanan ini sudah tidak menunggu persetujuan.']);
        }

        return $locked;
    }
}
