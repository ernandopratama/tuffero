<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\StaffEvent;
use App\Services\Shipping\BiteshipShipmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur perubahan status pesanan setelah checkout. Status asal selalu diperiksa di dalam kunci baris.
 */
class OrderStatusService
{
    public function __construct(private InventoryService $inventory, private StaffNotifier $notifier, private BiteshipShipmentService $biteship) {}

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

            $this->notifier->toRoles(['store-admin', 'warehouse'], new StaffEvent('order_paid', 'order', $locked->order_number, "Pesanan {$locked->order_number} sudah dibayar dan siap dikemas", $locked->customer_name));

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
     * "Proses Pesanan": buat resi lewat Biteship (default) atau catat resi manual, tanpa mengubah pesanan menjadi Dikirim.
     * Pesanan Dibayar naik ke Diproses; Dikirim baru setelah kurir mengambil paket (webhook `picked`) atau admin menekan Tandai Dikirim.
     * Panggilan Biteship terjadi di dalam kunci baris, jadi klik ganda tidak membuat dua order.
     */
    public function arrange(Order $order, User $actor, string $method, ?string $waybill = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $method, $waybill, $note) {
            $locked = $this->lock($order);

            if (! in_array($locked->status, [Order::PAID, Order::PROCESSING], true)) {
                throw ValidationException::withMessages(['status' => 'Hanya pesanan yang sudah dibayar yang bisa diproses.']);
            }

            $this->assertNoOpenCancellation($locked);

            if ($locked->shipment()->exists()) {
                throw ValidationException::withMessages(['status' => 'Pesanan ini sudah punya resi.']);
            }

            if ($method === 'biteship') {
                if (! $this->biteship->supports($locked)) {
                    throw ValidationException::withMessages(['status' => 'Biteship belum aktif untuk kurir ini. Pilih resi manual.']);
                }

                $created = $this->biteship->createOrder($locked->load('items'));

                $locked->shipment()->create([
                    'provider' => 'biteship',
                    'provider_order_id' => $created['provider_order_id'],
                    'tracking_id' => $created['tracking_id'],
                    'courier_code' => $locked->shipping_courier,
                    'service_code' => $locked->shipping_service,
                    'waybill_id' => $created['waybill_id'],
                    'status' => $created['status'],
                    'shipping_fee' => $locked->shipping_fee,
                ]);
            } else {
                $locked->shipment()->create([
                    'provider' => 'manual',
                    'courier_code' => $locked->shipping_courier,
                    'service_code' => $locked->shipping_service,
                    'waybill_id' => $waybill,
                    'note' => $note,
                    'status' => 'ready',
                    'shipping_fee' => $locked->shipping_fee,
                ]);
            }

            if ($locked->status === Order::PAID) {
                $this->transition($locked, Order::PROCESSING, 'packing', $actor);
            }

            return $locked;
        });
    }

    /** Paket sudah diserahkan ke kurir. Untuk Biteship biasanya otomatis dari webhook; tombol ini cadangan dan jalur resi manual. */
    public function markShipped(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);

            if ($locked->status === Order::SHIPPED) {
                return $locked;
            }

            $this->assertStatus($locked, Order::PROCESSING, 'Pesanan harus sedang diproses sebelum dikirim.');
            $this->assertNoOpenCancellation($locked);

            $shipment = $locked->shipment;

            if (! $shipment) {
                throw ValidationException::withMessages(['status' => 'Buat resi dulu lewat Proses Pesanan.']);
            }

            $shipment->update(['shipped_at' => now(), ...($shipment->provider === 'manual' ? ['status' => 'shipped'] : [])]);
            $this->transition($locked, Order::SHIPPED, 'shipped', $actor);

            return $locked;
        });
    }

    /**
     * Resi diisi manual (provider `manual`). Bila Proses Pesanan sudah membuat resi, resi itu diperbarui lalu pesanan dikirim.
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

            if ($existing = $locked->shipment) {
                if ($existing->provider !== 'manual') {
                    throw ValidationException::withMessages(['status' => 'Pesanan ini memakai resi Biteship. Gunakan Tandai Dikirim.']);
                }

                $existing->update(['waybill_id' => $waybill, 'status' => 'shipped', 'shipped_at' => now()]);
            } else {
                $locked->shipment()->create([
                    'provider' => 'manual',
                    'courier_code' => $locked->shipping_courier,
                    'service_code' => $locked->shipping_service,
                    'waybill_id' => $waybill,
                    'status' => 'shipped',
                    'shipping_fee' => $locked->shipping_fee,
                    'shipped_at' => now(),
                ]);
            }

            $this->transition($locked, Order::SHIPPED, 'shipped', $actor);

            return $locked;
        });
    }

    /**
     * Kirim lewat Biteship (D-082): order dibuat dan resi terisi otomatis. Panggilan API terjadi di dalam kunci baris, jadi klik ganda
     * menunggu lalu melihat status SHIPPED dan tidak membuat order kedua. Gagal = exception, tidak ada perubahan (pesanan tetap Diproses).
     */
    public function shipViaBiteship(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);

            if ($locked->status === Order::SHIPPED) {
                return $locked;
            }

            $this->assertStatus($locked, Order::PROCESSING, 'Pesanan harus sedang diproses sebelum dikirim.');
            $this->assertNoOpenCancellation($locked);

            if ($locked->shipment()->exists()) {
                throw ValidationException::withMessages(['status' => 'Pesanan ini sudah punya resi. Gunakan Tandai Dikirim.']);
            }

            if (! $this->biteship->supports($locked)) {
                throw ValidationException::withMessages(['status' => 'Biteship belum aktif untuk kurir ini. Isi nomor resi manual.']);
            }

            $created = $this->biteship->createOrder($locked->load('items'));

            $locked->shipment()->create([
                'provider' => 'biteship',
                'provider_order_id' => $created['provider_order_id'],
                'tracking_id' => $created['tracking_id'],
                'courier_code' => $locked->shipping_courier,
                'service_code' => $locked->shipping_service,
                'waybill_id' => $created['waybill_id'],
                'status' => $created['status'],
                'shipping_fee' => $locked->shipping_fee,
                'shipped_at' => now(),
            ]);

            $this->transition($locked, Order::SHIPPED, 'shipped', $actor);

            return $locked;
        });
    }

    /**
     * Pembaruan dari webhook Biteship (idempoten). Pesanan selesai hanya saat delivered dan hanya dari SHIPPED. Status bermasalah
     * (returned, rejected, dan seterusnya) dicatat dan diberitahukan ke staf, tanpa transisi pesanan.
     */
    public function applyCarrierUpdate(Shipment $shipment, ?string $status, ?string $waybill): void
    {
        DB::transaction(function () use ($shipment, $status, $waybill) {
            $order = $this->lock(Order::findOrFail($shipment->order_id));
            $shipment = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($waybill) {
                $shipment->waybill_id = $waybill;
            }

            if ($status && $shipment->status !== $status && $shipment->status !== 'delivered') {
                $shipment->status = $status;

                if ($status === 'delivered') {
                    $shipment->delivered_at = now();
                } elseif (in_array($status, ['returned', 'return_in_transit', 'rejected', 'cancelled', 'courier_not_found', 'on_hold', 'disposed'], true)) {
                    $this->notifier->toRoles(['store-admin', 'warehouse'], new StaffEvent('shipment_problem', 'order', $order->order_number, "Pengiriman {$order->order_number} berstatus {$status} di Biteship. Periksa dan tindak lanjuti", $order->customer_name));
                }
            }

            // Kurir sudah mengambil paket: pesanan yang menunggu diserahkan otomatis menjadi Dikirim.
            if ($order->status === Order::PROCESSING && in_array($shipment->status, ['picked', 'dropping_off', 'delivered'], true)) {
                $shipment->shipped_at ??= now();
                $from = $order->shipping_status;
                $order->forceFill(['status' => Order::SHIPPED, 'shipping_status' => 'shipped'])->save();
                $order->histories()->create(['status_type' => 'order', 'from_status' => Order::PROCESSING, 'to_status' => Order::SHIPPED, 'source' => 'biteship_webhook']);
                $order->histories()->create(['status_type' => 'shipping', 'from_status' => $from, 'to_status' => 'shipped', 'source' => 'biteship_webhook']);
            }

            $shipment->save();

            if ($shipment->status === 'delivered' && $order->status === Order::SHIPPED) {
                $from = $order->shipping_status;
                $order->forceFill(['status' => Order::COMPLETED, 'shipping_status' => 'delivered'])->save();
                $order->histories()->create(['status_type' => 'order', 'from_status' => Order::SHIPPED, 'to_status' => Order::COMPLETED, 'source' => 'biteship_webhook']);
                $order->histories()->create(['status_type' => 'shipping', 'from_status' => $from, 'to_status' => 'delivered', 'source' => 'biteship_webhook']);
            }
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
