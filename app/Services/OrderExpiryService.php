<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Menutup pesanan yang lewat batas bayar dan melepas stoknya (TRX-06).
 */
class OrderExpiryService
{
    public function __construct(private InventoryService $inventory, private VoucherService $vouchers) {}

    public function expireDue(): int
    {
        $expired = 0;

        // Menunggu persetujuan yang lewat tenggat tinjau juga ditutup supaya stok tidak tertahan (D-132).
        Order::whereIn('status', [Order::PENDING_PAYMENT, Order::AWAITING_APPROVAL])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$expired) {
                $expired += DB::transaction(function () use ($id) {
                    $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();

                    // Diperiksa ulang di dalam kunci: pembayaran bisa saja masuk lebih dulu.
                    $from = $order->status;
                    if (! in_array($from, [Order::PENDING_PAYMENT, Order::AWAITING_APPROVAL], true)) {
                        return 0;
                    }
                    $note = $from === Order::AWAITING_APPROVAL ? 'Batas tinjau lewat' : 'Batas bayar lewat';

                    $this->inventory->releaseOrder($order, $note);
                    $this->vouchers->release($order);

                    $order->update(['status' => Order::EXPIRED, 'payment_status' => 'expired']);
                    $order->histories()->create(['status_type' => 'order', 'from_status' => $from, 'to_status' => Order::EXPIRED, 'source' => 'system', 'reason' => $from === Order::AWAITING_APPROVAL ? $note : null]);

                    return 1;
                }, 3);
            });

        return $expired;
    }
}
