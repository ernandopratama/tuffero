<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur perubahan status pesanan setelah checkout. Status asal selalu diperiksa di dalam kunci baris.
 */
class OrderStatusService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * Pembayaran terverifikasi. Hanya untuk dipanggil dari notifikasi Midtrans (BR-11), tidak ada route admin untuk ini.
     */
    public function markPaid(Order $order, string $source = 'midtrans_webhook'): Order
    {
        return DB::transaction(function () use ($order, $source) {
            $locked = $this->lock($order);

            if ($locked->status !== Order::PENDING_PAYMENT) {
                return $locked;
            }

            $this->inventory->consumeOrder($locked);

            $locked->forceFill(['status' => Order::PAID, 'payment_status' => 'paid', 'paid_at' => now()])->save();
            $locked->histories()->create(['status_type' => 'payment', 'from_status' => 'pending', 'to_status' => 'paid', 'source' => $source]);
            $locked->histories()->create(['status_type' => 'order', 'from_status' => Order::PENDING_PAYMENT, 'to_status' => Order::PAID, 'source' => $source]);

            return $locked;
        }, 3);
    }

    public function startProcessing(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);
            $this->assertStatus($locked, Order::PAID, 'Hanya pesanan yang sudah dibayar yang bisa diproses.');
            $this->assertNoOpenCancellation($locked);

            $this->transition($locked, Order::PROCESSING, 'packing', $actor);

            return $locked;
        });
    }

    /**
     * Resi diisi manual sampai Biteship tersambung (provider `manual`).
     */
    public function ship(Order $order, string $waybill, User $actor): Order
    {
        return DB::transaction(function () use ($order, $waybill, $actor) {
            $locked = $this->lock($order);

            // Pesanan yang sudah dikirim hanya boleh dikoreksi nomor resinya.
            if ($locked->status === Order::SHIPPED) {
                $shipment = $locked->shipment()->firstOrFail();
                AuditLog::saveWithAudit($shipment, ['waybill_id' => $waybill], 'shipment.waybill_corrected', $actor);

                return $locked;
            }

            $this->assertStatus($locked, Order::PROCESSING, 'Pesanan harus sedang diproses sebelum dikirim.');
            $this->assertNoOpenCancellation($locked);

            $locked->shipment()->create([
                'provider' => 'manual',
                'courier_code' => $locked->shipping_courier,
                'service_code' => $locked->shipping_service,
                'waybill_id' => $waybill,
                'status' => 'shipped',
                'shipping_fee' => $locked->shipping_fee,
                'shipped_at' => now(),
            ]);

            $this->transition($locked, Order::SHIPPED, 'shipped', $actor);

            return $locked;
        });
    }

    public function complete(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);
            $this->assertStatus($locked, Order::SHIPPED, 'Hanya pesanan yang sudah dikirim yang bisa diselesaikan.');

            /** @var Shipment $shipment */
            $shipment = $locked->shipment()->firstOrFail();
            $shipment->update(['status' => 'delivered', 'delivered_at' => now()]);

            $this->transition($locked, Order::COMPLETED, 'delivered', $actor);

            return $locked;
        });
    }

    private function lock(Order $order): Order
    {
        return Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function assertStatus(Order $order, string $expected, string $message): void
    {
        if ($order->status !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function assertNoOpenCancellation(Order $order): void
    {
        if ($order->cancellationRequests()->whereIn('status', OrderCancellationRequest::OPEN)->exists()) {
            throw ValidationException::withMessages(['status' => 'Ada pengajuan pembatalan yang belum selesai.']);
        }
    }

    private function transition(Order $order, string $status, string $shippingStatus, User $actor): void
    {
        $from = $order->status;
        $fromShipping = $order->shipping_status;

        $order->forceFill(['status' => $status, 'shipping_status' => $shippingStatus])->save();
        $order->histories()->create(['status_type' => 'order', 'from_status' => $from, 'to_status' => $status, 'source' => 'admin', 'actor_user_id' => $actor->id]);
        $order->histories()->create(['status_type' => 'shipping', 'from_status' => $fromShipping, 'to_status' => $shippingStatus, 'source' => 'admin', 'actor_user_id' => $actor->id]);
    }
}
