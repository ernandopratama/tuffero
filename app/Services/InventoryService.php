<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockReservation;
use App\Models\User;
use App\Notifications\StaffEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur perubahan stok manual: transaksi + lockForUpdate supaya stok tidak minus (BR-10, TRX-04).
 */
class InventoryService
{
    public function __construct(private StaffNotifier $notifier) {}

    public function adjust(int $warehouseId, int $variantId, string $type, int $change, string $note, User $actor): InventoryStock
    {
        if ($type === 'restock' && $change <= 0) {
            throw ValidationException::withMessages(['quantity_change' => 'Stok masuk harus lebih dari 0.']);
        }

        return DB::transaction(function () use ($warehouseId, $variantId, $type, $change, $note, $actor) {
            InventoryStock::createOrFirst(['warehouse_id' => $warehouseId, 'product_variant_id' => $variantId]);

            $stock = InventoryStock::where(['warehouse_id' => $warehouseId, 'product_variant_id' => $variantId])
                ->lockForUpdate()
                ->firstOrFail();

            $after = $stock->quantity + $change;
            $availableBefore = $stock->quantity - $stock->reserved;

            if ($after < $stock->reserved) {
                throw ValidationException::withMessages([
                    'quantity_change' => "Stok tidak cukup. Stok {$stock->quantity}, sedang dipesan {$stock->reserved}.",
                ]);
            }

            $stock->update(['quantity' => $after]);

            $stock->movements()->create([
                'type' => $type,
                'quantity_change' => $change,
                'reserved_change' => 0,
                'quantity_after' => $after,
                'reserved_after' => $stock->reserved,
                'actor_user_id' => $actor->id,
                'note' => $note,
            ]);

            AuditLog::record('stock.adjusted', $stock, [
                'type' => $type,
                'old' => $after - $change,
                'new' => $after,
                'note' => $note,
            ], $actor->id);

            $this->warnIfLow($stock, $availableBefore, $actor);

            return $stock;
        }, 3);
    }

    /**
     * Menahan stok untuk pesanan. Dipanggil di dalam transaksi checkout; gagal bila stok habis direbut pesanan lain.
     */
    public function reserve(Order $order, int $variantId, int $quantity, CarbonInterface $expiresAt): void
    {
        $stock = InventoryStock::where(['warehouse_id' => $order->warehouse_id, 'product_variant_id' => $variantId])
            ->lockForUpdate()
            ->first();

        if (! $stock || $stock->quantity - $stock->reserved < $quantity) {
            throw ValidationException::withMessages(['cart' => 'stock_gone']);
        }

        $availableBefore = $stock->quantity - $stock->reserved;
        $stock->update(['reserved' => $stock->reserved + $quantity]);
        $this->warnIfLow($stock, $availableBefore);

        $stock->movements()->create([
            'type' => 'reserve',
            'quantity_change' => 0,
            'reserved_change' => $quantity,
            'quantity_after' => $stock->quantity,
            'reserved_after' => $stock->reserved,
            'order_id' => $order->id,
        ]);

        StockReservation::create([
            'order_id' => $order->id,
            'warehouse_id' => $order->warehouse_id,
            'product_variant_id' => $variantId,
            'quantity' => $quantity,
            'status' => 'active',
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Memberi tahu Admin Toko dan Gudang sekali saat stok tersedia (stok dikurangi yang ditahan) turun ke bawah batas,
     * bukan setiap kali berkurang.
     */
    private function warnIfLow(InventoryStock $stock, int $availableBefore, ?User $except = null): void
    {
        // Bawaan sama dengan label "menipis" di dashboard (D-055): tersedia di bawah 10.
        $limit = (int) (Setting::where('key', 'inventory.low_stock_threshold')->value('value') ?? DashboardService::LOW_STOCK);
        $available = $stock->quantity - $stock->reserved;

        if ($availableBefore < $limit || $available >= $limit) {
            return;
        }

        $variant = ProductVariant::with('product:id,name')->find($stock->product_variant_id);
        $name = $variant ? "{$variant->sku} ({$variant->product?->name})" : "varian #{$stock->product_variant_id}";

        $this->notifier->toRoles(['store-admin', 'warehouse'], new StaffEvent('low_stock', 'stock', $variant->sku ?? (string) $stock->product_variant_id, "Stok {$name} menipis: tersisa {$available}", "Peringatan saat stok tersedia di bawah {$limit}"), $except);
    }

    /**
     * Stok yang ditahan menjadi terjual saat pembayaran terverifikasi. Dipanggil di dalam transaksi pemanggil.
     */
    public function consumeOrder(Order $order): void
    {
        foreach ($order->reservations()->where('status', 'active')->orderBy('product_variant_id')->get() as $reservation) {
            $stock = $this->lockedStock($reservation);

            $stock->update(['quantity' => $stock->quantity - $reservation->quantity, 'reserved' => $stock->reserved - $reservation->quantity]);

            $stock->movements()->create([
                'type' => 'sale',
                'quantity_change' => -$reservation->quantity,
                'reserved_change' => -$reservation->quantity,
                'quantity_after' => $stock->quantity,
                'reserved_after' => $stock->reserved,
                'order_id' => $order->id,
            ]);

            $reservation->update(['status' => 'consumed']);
        }
    }

    /**
     * Mengembalikan stok pesanan terbayar yang dibatalkan sebelum dikirim. Dipanggil di dalam transaksi pemanggil.
     */
    public function returnOrder(Order $order, string $note): void
    {
        foreach ($order->reservations()->where('status', 'consumed')->orderBy('product_variant_id')->get() as $reservation) {
            $stock = $this->lockedStock($reservation);

            $stock->update(['quantity' => $stock->quantity + $reservation->quantity]);

            $stock->movements()->create([
                'type' => 'return',
                'quantity_change' => $reservation->quantity,
                'reserved_change' => 0,
                'quantity_after' => $stock->quantity,
                'reserved_after' => $stock->reserved,
                'order_id' => $order->id,
                'note' => $note,
            ]);

            $reservation->update(['status' => 'released', 'released_at' => now()]);
        }
    }

    private function lockedStock(StockReservation $reservation): InventoryStock
    {
        return InventoryStock::where(['warehouse_id' => $reservation->warehouse_id, 'product_variant_id' => $reservation->product_variant_id])
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Melepas semua stok yang masih ditahan pesanan. Dipanggil di dalam transaksi pemanggil.
     */
    public function releaseOrder(Order $order, string $note): void
    {
        $reservations = $order->reservations()->where('status', 'active')->orderBy('product_variant_id')->get();

        foreach ($reservations as $reservation) {
            $stock = InventoryStock::where(['warehouse_id' => $reservation->warehouse_id, 'product_variant_id' => $reservation->product_variant_id])
                ->lockForUpdate()
                ->firstOrFail();

            $stock->update(['reserved' => $stock->reserved - $reservation->quantity]);

            $stock->movements()->create([
                'type' => 'release',
                'quantity_change' => 0,
                'reserved_change' => -$reservation->quantity,
                'quantity_after' => $stock->quantity,
                'reserved_after' => $stock->reserved,
                'order_id' => $order->id,
                'note' => $note,
            ]);

            $reservation->update(['status' => 'released', 'released_at' => now()]);
        }
    }
}
