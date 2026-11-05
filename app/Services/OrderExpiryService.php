<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Menutup pesanan yang lewat batas bayar dan melepas stoknya (TRX-06).
 */
class OrderExpiryService
{
    public function __construct(private InventoryService $inventory) {}

    public function expireDue(): int
    {
        $expired = 0;

        Order::where('status', Order::PENDING_PAYMENT)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$expired) {
                $expired += DB::transaction(function () use ($id) {
                    $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();

                    // Diperiksa ulang di dalam kunci: pembayaran bisa saja masuk lebih dulu.
                    if ($order->status !== Order::PENDING_PAYMENT) {
                        return 0;
                    }

                    $this->inventory->releaseOrder($order, 'Batas bayar lewat');

                    $order->update(['status' => Order::EXPIRED, 'payment_status' => 'expired']);
                    $order->histories()->create(['status_type' => 'order', 'from_status' => Order::PENDING_PAYMENT, 'to_status' => Order::EXPIRED, 'source' => 'system']);

                    return 1;
                }, 3);
            });

        return $expired;
    }
}
