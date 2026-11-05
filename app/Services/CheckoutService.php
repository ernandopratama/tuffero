<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Shipping\ShippingProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Membuat pesanan dari keranjang. Harga, ongkir, stok, dan total dihitung di server (BR-08), satu gudang per pesanan (BR-06).
 */
class CheckoutService
{
    public function __construct(
        private CartService $cart,
        private ShippingProvider $shipping,
        private InventoryService $inventory,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Data pelanggan dan alamat yang sudah divalidasi.
     * @return array{order: Order, token: string|null}
     */
    public function place(array $data, string $idempotencyKey): array
    {
        $existing = Order::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return ['order' => $existing, 'token' => null];
        }

        $this->guardAgainstAbuse($data['customer_phone']);

        $summary = $this->cart->summary();

        if (! $summary['can_checkout']) {
            throw ValidationException::withMessages(['cart' => 'cart_changed']);
        }

        $rate = collect($this->shipping->rates($data['address'], $summary['weight_gram']))
            ->first(fn (array $rate) => $rate['courier'] === $data['courier'] && $rate['service'] === $data['service']);

        if (! $rate) {
            throw ValidationException::withMessages(['courier' => 'courier_unavailable']);
        }

        $token = bin2hex(random_bytes(32));

        try {
            $order = DB::transaction(fn () => $this->createOrder($data, $summary, $rate, $idempotencyKey, $token), 3);
        } catch (UniqueConstraintViolationException $e) {
            // Dua kiriman bersamaan: pemenang sudah menyimpan pesanan.
            $order = Order::where('idempotency_key', $idempotencyKey)->first();

            if (! $order) {
                throw $e;
            }

            return ['order' => $order, 'token' => null];
        }

        $this->cart->clear();

        return ['order' => $order, 'token' => $token];
    }

    /**
     * Anti-fraud tahap keras (OQ-07): batas pesanan belum dibayar dan pesanan per 24 jam untuk satu nomor HP.
     * Pesanan belum dibayar menahan stok (D-050), jadi tanpa batas ini stok bisa ditimbun.
     */
    private function guardAgainstAbuse(string $phone): void
    {
        $limit = fn (string $key, int $default) => (int) Setting::where('key', $key)->value('value') ?: $default;

        $unpaid = Order::where('customer_phone', $phone)->where('status', Order::PENDING_PAYMENT)->count();

        if ($unpaid >= $limit('antifraud.max_unpaid_orders', 3)) {
            throw ValidationException::withMessages(['customer_phone' => 'too_many_unpaid_orders']);
        }

        $today = Order::where('customer_phone', $phone)->where('created_at', '>=', now()->subDay())->count();

        if ($today >= $limit('antifraud.max_orders_per_day', 5)) {
            throw ValidationException::withMessages(['customer_phone' => 'daily_order_limit']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{items: list<array<string, mixed>>, subtotal: int, weight_gram: int, can_checkout: bool}  $summary
     * @param  array{courier: string, service: string, label: string, fee: int}  $rate
     */
    private function createOrder(array $data, array $summary, array $rate, string $idempotencyKey, string $token): Order
    {
        $warehouse = $this->allocateWarehouse($summary['items']);
        $minutes = (int) Setting::where('key', 'checkout.reservation_minutes')->value('value') ?: 60;
        $expiresAt = now()->addMinutes($minutes);

        $order = Order::create([
            'user_id' => $data['user_id'] ?? null,
            'public_ref' => (string) Str::uuid(),
            'order_number' => $this->orderNumber(),
            'access_token_hash' => hash('sha256', $token),
            'idempotency_key' => $idempotencyKey,
            'warehouse_id' => $warehouse->id,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'customer_phone' => $data['customer_phone'],
            'shipping_address' => $data['address'],
            'shipping_courier' => $rate['courier'],
            'shipping_service' => $rate['service'],
            'status' => Order::PENDING_PAYMENT,
            'payment_status' => 'pending',
            'shipping_status' => 'pending',
            'subtotal' => $summary['subtotal'],
            'discount_total' => 0,
            'shipping_fee' => $rate['fee'],
            'total' => $summary['subtotal'] + $rate['fee'],
            'expires_at' => $expiresAt,
        ]);

        foreach ($summary['items'] as $item) {
            $order->items()->create([
                'product_variant_id' => $item['variant_id'],
                'sku_snapshot' => $item['sku'],
                'product_name_snapshot' => $item['product_name'],
                'variant_name_snapshot' => $item['variant_name'],
                'unit_price' => $item['price'],
                'quantity' => $item['quantity'],
                'weight_gram_snapshot' => $item['weight_gram'],
                'line_total' => $item['line_total'],
            ]);
        }

        // Urutan tetap supaya dua checkout bersamaan tidak saling menunggu (deadlock).
        foreach (collect($summary['items'])->sortBy('variant_id') as $item) {
            $this->inventory->reserve($order, $item['variant_id'], $item['quantity'], $expiresAt);
        }

        $order->histories()->create(['status_type' => 'order', 'to_status' => Order::PENDING_PAYMENT, 'source' => 'checkout']);

        return $order;
    }

    /**
     * Gudang aktif pertama yang bisa memenuhi semua item. Pesanan tidak pernah dipecah (BR-07).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function allocateWarehouse(array $items): Warehouse
    {
        foreach (Warehouse::where('is_active', true)->orderBy('id')->get() as $warehouse) {
            $stocks = InventoryStock::where('warehouse_id', $warehouse->id)
                ->whereIn('product_variant_id', array_column($items, 'variant_id'))
                ->get()
                ->keyBy('product_variant_id');

            $canFulfill = collect($items)->every(function (array $item) use ($stocks) {
                $stock = $stocks->get($item['variant_id']);

                return $stock && $stock->quantity - $stock->reserved >= $item['quantity'];
            });

            if ($canFulfill) {
                return $warehouse;
            }
        }

        throw ValidationException::withMessages(['cart' => 'stock_split']);
    }

    // Acak, bukan berurutan: nomor berurutan membocorkan jumlah pesanan dan mudah ditebak.
    private function orderNumber(): string
    {
        do {
            $number = 'TF'.now()->format('ym').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
