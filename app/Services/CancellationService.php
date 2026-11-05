<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCancellationRequest as CancellationRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur pembatalan D-043 dan D-044. Sistem tidak pernah menyetujui apa pun secara otomatis.
 */
class CancellationService
{
    public function __construct(private InventoryService $inventory) {}

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

            return $locked->cancellationRequests()->create([
                'request_number' => $this->requestNumber(),
                'reason' => $data['reason'],
                'channel' => $data['channel'],
                'status' => CancellationRequest::SUBMITTED,
                'due_at' => now()->addDays($days),
            ]);
        });
    }

    public function validateRequest(CancellationRequest $request, User $actor): CancellationRequest
    {
        return $this->step($request, CancellationRequest::SUBMITTED, function (CancellationRequest $locked) use ($actor) {
            $locked->forceFill([
                'status' => CancellationRequest::VALIDATED,
                'validated_by_user_id' => $actor->id,
                'validated_at' => now(),
            ])->save();
        });
    }

    public function approveByStore(CancellationRequest $request, User $actor): CancellationRequest
    {
        return $this->step($request, CancellationRequest::VALIDATED, function (CancellationRequest $locked, Order $order) use ($actor) {
            $locked->forceFill(['store_approved_by_user_id' => $actor->id, 'store_approved_at' => now()]);

            // Pesanan belum dibayar selesai di ACC Admin Toko; Pemilik hanya memantau (D-044).
            if ($order->payment_status === 'paid') {
                $locked->forceFill(['status' => CancellationRequest::STORE_APPROVED])->save();

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

        $toPayment = $wasPaid ? 'refund_pending' : 'cancelled';

        $order->forceFill(['status' => Order::CANCELLED, 'payment_status' => $toPayment, 'cancelled_at' => now()])->save();
        $order->histories()->create(['status_type' => 'order', 'from_status' => $fromStatus, 'to_status' => Order::CANCELLED, 'source' => 'admin', 'actor_user_id' => $actor->id, 'reason' => $note]);
        $order->histories()->create(['status_type' => 'payment', 'from_status' => $fromPayment, 'to_status' => $toPayment, 'source' => 'admin', 'actor_user_id' => $actor->id]);

        $request->forceFill(['status' => CancellationRequest::APPROVED])->save();
    }

    private function requestNumber(): string
    {
        do {
            $number = 'BTL'.now()->format('ym').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (CancellationRequest::where('request_number', $number)->exists());

        return $number;
    }
}
