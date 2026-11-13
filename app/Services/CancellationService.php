<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest as CancellationRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur pembatalan D-043 dan D-044. Sistem tidak pernah menyetujui apa pun secara otomatis.
 */
class CancellationService
{
    public function __construct(private InventoryService $inventory, private VoucherService $vouchers, private StaffNotifier $notifier) {}

    /** @param array{reason: string, channel: string} $data */
    public function submit(Order $order, array $data): CancellationRequest
    {
        return DB::transaction(function () use ($order, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canBeCancelled()) {
                throw ValidationException::withMessages(['order' => 'not_cancellable']);
            }

            if ($locked->cancellationRequests()->whereIn('status', CancellationRequest::OPEN)->exists()) {
                throw ValidationException::withMessages(['order' => 'already_requested']);
            }

            $days = (int) Setting::where('key', 'cancellation.process_days_max')->value('value') ?: 7;

            $request = $locked->cancellationRequests()->create([
                'request_number' => $this->requestNumber(),
                'reason' => $data['reason'],
                'channel' => $data['channel'],
                'status' => CancellationRequest::SUBMITTED,
                'due_at' => now()->addDays($days),
            ]);

            $this->notify(['customer-service'], 'cancellation_submitted', $request, $locked, "Pengajuan pembatalan {$request->request_number} menunggu validasi", $data['reason']);

            return $request;
        });
    }

    public function validateRequest(CancellationRequest $request, User $actor): CancellationRequest
    {
        return $this->step($request, CancellationRequest::SUBMITTED, function (CancellationRequest $locked, Order $order) use ($actor) {
            $locked->forceFill([
                'status' => CancellationRequest::VALIDATED,
                'validated_by_user_id' => $actor->id,
                'validated_at' => now(),
            ])->save();

            $this->notify(['store-admin'], 'cancellation_validated', $locked, $order, "Pengajuan {$locked->request_number} menunggu ACC Admin Toko", "Pesanan {$order->order_number}", $actor);
        });
    }

    public function approveByStore(CancellationRequest $request, User $actor): CancellationRequest
    {
        return $this->step($request, CancellationRequest::VALIDATED, function (CancellationRequest $locked, Order $order) use ($actor) {
            $locked->forceFill(['store_approved_by_user_id' => $actor->id, 'store_approved_at' => now()]);

            // Pesanan belum dibayar selesai di ACC Admin Toko; Pemilik hanya memantau (D-044).
            if ($order->payment_status === 'paid') {
                $locked->forceFill(['status' => CancellationRequest::STORE_APPROVED])->save();
                $this->notify(['owner'], 'cancellation_store_approved', $locked, $order, "Pengajuan {$locked->request_number} menunggu ACC Pemilik", "Pesanan {$order->order_number}", $actor);

                return;
            }

            $this->finalize($locked, $order, $actor);
        });
    }

    public function approveByOwner(CancellationRequest $request, User $actor): CancellationRequest
    {
        return $this->step($request, CancellationRequest::STORE_APPROVED, function (CancellationRequest $locked, Order $order) use ($actor) {
            if ($locked->store_approved_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['request' => 'Dua ACC harus dari orang yang berbeda.']);
            }

            $locked->forceFill(['owner_approved_by_user_id' => $actor->id, 'owner_approved_at' => now()]);

            $this->finalize($locked, $order, $actor);
        });
    }

    public function reject(CancellationRequest $request, string $reason, User $actor): CancellationRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor) {
            $locked = CancellationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, CancellationRequest::OPEN, true)) {
                throw ValidationException::withMessages(['request' => 'Pengajuan ini sudah selesai.']);
            }

            $locked->forceFill([
                'status' => CancellationRequest::REJECTED,
                'rejected_by_user_id' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            return $locked;
        });
    }

    /**
     * Dana pesanan yang dibatalkan sudah sampai ke pelanggan. Transfer dilakukan manual di luar sistem (D-031).
     */
    public function markRefunded(CancellationRequest $request, User $actor): CancellationRequest
    {
        return $this->step($request, CancellationRequest::APPROVED, function (CancellationRequest $locked, Order $order) use ($actor) {
            if ($order->payment_status !== 'refund_pending') {
                throw ValidationException::withMessages(['request' => 'Pesanan ini tidak perlu pengembalian dana.']);
            }

            $locked->forceFill(['status' => CancellationRequest::REFUNDED, 'refunded_by_user_id' => $actor->id, 'refunded_at' => now()])->save();

            $order->forceFill(['status' => Order::REFUNDED, 'payment_status' => 'refunded'])->save();
            $order->histories()->create(['status_type' => 'payment', 'from_status' => 'refund_pending', 'to_status' => 'refunded', 'source' => 'admin', 'actor_user_id' => $actor->id]);
            $order->histories()->create(['status_type' => 'order', 'from_status' => Order::CANCELLED, 'to_status' => Order::REFUNDED, 'source' => 'admin', 'actor_user_id' => $actor->id]);
        });
    }

    /**
     * Dana masuk untuk pesanan yang sudah ditutup (kedaluwarsa atau dibatalkan sebelum bayar) sudah dikembalikan ke pelanggan.
     * Pesanan ini tidak punya pengajuan pembatalan, jadi tidak lewat markRefunded. Cukup satu Admin Toko; catatan wajib (misalnya
     * referensi transfer) dan tercatat di riwayat status dan audit log. Hanya mengubah payment_status; status pesanan, stok,
     * dan voucher tidak disentuh (sudah dilepas saat pesanan ditutup). Transfernya dilakukan manual di luar sistem (D-031).
     */
    public function markLatePaymentRefunded(Order $order, string $note, User $actor): Order
    {
        return DB::transaction(function () use ($order, $note, $actor) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Diperiksa ulang di dalam kunci: klik ganda atau dua admin bersamaan tidak mencatat dua kali.
            if (! Order::latePaymentClosed()->whereKey($locked->id)->exists()) {
                throw ValidationException::withMessages(['order' => 'Pesanan ini tidak punya dana masuk yang perlu dikembalikan.']);
            }

            $from = $locked->payment_status;
            $locked->forceFill(['payment_status' => 'refunded'])->save();
            $locked->histories()->create(['status_type' => 'payment', 'from_status' => $from, 'to_status' => 'refunded', 'source' => 'admin', 'actor_user_id' => $actor->id, 'reason' => $note]);
            AuditLog::record('order.late_payment_refunded', $locked, ['note' => $note, 'total' => (int) $locked->total], $actor->id);

            return $locked;
        }, 3);
    }

    /** @param callable(CancellationRequest, Order): void $action */
    private function step(CancellationRequest $request, string $expected, callable $action): CancellationRequest
    {
        return DB::transaction(function () use ($request, $expected, $action) {
            // Urutan kunci sama dengan OrderStatusService: pesanan dulu, supaya tidak saling menunggu.
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $locked = CancellationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $expected) {
                throw ValidationException::withMessages(['request' => 'Status pengajuan sudah berubah. Muat ulang halaman.']);
            }

            $action($locked, $order);

            return $locked;
        }, 3);
    }

    private function finalize(CancellationRequest $request, Order $order, User $actor): void
    {
        if (! $order->canBeCancelled()) {
            throw ValidationException::withMessages(['request' => 'Pesanan ini sudah tidak bisa dibatalkan.']);
        }

        $note = "Pembatalan {$request->request_number}";
        $wasPaid = $order->payment_status === 'paid';
        $fromStatus = $order->status;
        $fromPayment = $order->payment_status;

        if ($wasPaid) {
            $this->inventory->returnOrder($order, $note);
        } else {
            $this->inventory->releaseOrder($order, $note);
        }
        $this->vouchers->release($order);

        $toPayment = $wasPaid ? 'refund_pending' : 'cancelled';

        $order->forceFill(['status' => Order::CANCELLED, 'payment_status' => $toPayment, 'cancelled_at' => now()])->save();
        $order->histories()->create(['status_type' => 'order', 'from_status' => $fromStatus, 'to_status' => Order::CANCELLED, 'source' => 'admin', 'actor_user_id' => $actor->id, 'reason' => $note]);
        $order->histories()->create(['status_type' => 'payment', 'from_status' => $fromPayment, 'to_status' => $toPayment, 'source' => 'admin', 'actor_user_id' => $actor->id]);

        $request->forceFill(['status' => CancellationRequest::APPROVED])->save();

        if ($wasPaid) {
            $this->notify(['store-admin'], 'refund_pending', $request, $order, "Dana pesanan {$order->order_number} menunggu dikembalikan", "Pengajuan {$request->request_number}", $actor);
        }
    }

    /**
     * Pengingat untuk pengajuan yang lewat batas proses (sistem hanya mengingatkan, tidak menyetujui apa pun). Sekali per pengajuan.
     */
    public function remindOverdue(): int
    {
        $count = 0;

        CancellationRequest::with('order')
            ->whereIn('status', CancellationRequest::OPEN)
            ->whereNull('overdue_notified_at')
            ->where('due_at', '<', now())
            ->each(function (CancellationRequest $request) use (&$count) {
                $request->forceFill(['overdue_notified_at' => now()])->save();
                $this->notify(['customer-service', 'store-admin'], 'cancellation_overdue', $request, $request->order, "Pengajuan {$request->request_number} lewat batas proses", "Pesanan {$request->order->order_number}");
                $count++;
            });

        return $count;
    }

    /** @param list<string> $roles */
    private function notify(array $roles, string $event, CancellationRequest $request, Order $order, string $title, string $excerpt, ?User $actor = null): void
    {
        $this->notifier->toRoles($roles, new StaffEvent($event, 'cancellation', $request->request_number, $title, $excerpt), $actor);
    }

    private function requestNumber(): string
    {
        do {
            $number = 'BTL'.now()->format('ym').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (CancellationRequest::where('request_number', $number)->exists());

        return $number;
    }
}
