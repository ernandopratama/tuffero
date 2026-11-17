<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Warehouse;
use App\Notifications\StaffEvent;
use App\Services\Shipping\ShippingProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
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
        private VoucherService $vouchers,
        private OrderReviewService $review,
        private StaffNotifier $notifier,
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

        $summary = $this->cart->summary();

        if (! $summary['can_checkout']) {
            throw ValidationException::withMessages(['cart' => 'cart_changed']);
        }

        // Hanya item yang dicentang yang dibeli; sisanya tetap di keranjang.
        $summary['items'] = array_values(array_filter($summary['items'], fn (array $item) => $item['selected']));

        $rate = collect($this->shipping->rates($data['address'], $summary['weight_gram']))
            ->first(fn (array $rate) => $rate['courier'] === $data['courier'] && $rate['service'] === $data['service']);

        if (! $rate) {
            throw ValidationException::withMessages(['courier' => 'courier_unavailable']);
        }

        $token = bin2hex(random_bytes(32));

        try {
            // Satu checkout per nomor HP sekaligus, supaya total belum dibayar (D-132) tidak bisa dilewati dengan kiriman bersamaan.
            $order = Cache::lock('checkout-phone:'.sha1($data['customer_phone']), 30)->block(10, function () use ($data, $summary, $rate, $idempotencyKey, $token) {
                $this->guardAgainstAbuse($data['customer_phone'], $data['address']);

                return DB::transaction(fn () => $this->createOrder($data, $summary, $rate, $idempotencyKey, $token), 3);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['customer_phone' => 'try_again']);
        } catch (UniqueConstraintViolationException $e) {
            // Dua kiriman bersamaan: pemenang sudah menyimpan pesanan.
            $order = Order::where('idempotency_key', $idempotencyKey)->first();

            if (! $order) {
                throw $e;
            }

            return ['order' => $order, 'token' => null];
        }

        foreach ($summary['items'] as $item) {
            $this->cart->remove($item['sku']);
        }

        return ['order' => $order, 'token' => $token];
    }

    /**
     * Anti-fraud tahap keras (OQ-07, D-132): satu pesanan menunggu persetujuan per nomor HP, dan batas pesanan per 24 jam.
     * Batas nominal belum dibayar tidak menolak checkout; pesanannya menunggu persetujuan Admin Toko (lihat createOrder).
     */
    private function guardAgainstAbuse(string $phone, array $address): void
    {
        // D-122: nomor atau alamat yang diblokir karena retur berturut-turut tidak bisa membuat pesanan baru.
        if (CustomerBlockService::blocks($phone, $address)) {
            throw ValidationException::withMessages(['customer_phone' => 'customer_blocked']);
        }

        if (Order::where('customer_phone', $phone)->where('status', Order::AWAITING_APPROVAL)->exists()) {
            throw ValidationException::withMessages(['customer_phone' => 'approval_pending']);
        }

        $today = Order::where('customer_phone', $phone)->where('created_at', '>=', now()->subDay())->count();

        if ($today >= OrderReviewService::setting('antifraud.max_orders_per_day', 5)) {
            throw ValidationException::withMessages(['customer_phone' => 'daily_order_limit']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{items: list<array<string, mixed>>, subtotal: int, saving: int, weight_gram: int, can_checkout: bool, selected_count: int}  $summary
     * @param  array{courier: string, service: string, label: string, fee: int}  $rate
     */
    private function createOrder(array $data, array $summary, array $rate, string $idempotencyKey, string $token): Order
    {
        $warehouse = $this->allocateWarehouse($summary['items']);

        // Voucher dikunci dan dihitung di dalam transaksi ini; kode salah menggagalkan seluruh checkout.
        $codes = array_values(array_filter([...($data['voucher_codes'] ?? []), $data['voucher_code'] ?? null], 'filled'));
        $applied = $codes
            ? $this->vouchers->redeem($codes, $summary['subtotal'], $data['user_id'] ?? null, $data['customer_phone'], $rate['fee'], $data['address']['province'])
            : [];
        // Voucher gratis ongkir memotong ongkir, bukan subtotal; shipping_fee tetap tarif penuh dari kurir (D-126).
        $shippingRow = collect($applied)->first(fn (array $row) => $row['voucher']->isShipping());
        $regular = array_values(array_filter($applied, fn (array $row) => ! $row['voucher']->isShipping()));
        $discount = array_sum(array_column($regular, 'discount'));
        $shippingDiscount = $shippingRow['discount'] ?? 0;
        $total = $summary['subtotal'] - $discount + $rate['fee'] - $shippingDiscount;

        // D-132: total Rp1 juta ke atas ditandai untuk ditinjau. Bila total belum dibayar nomor HP ini (pesanan yang masih bisa
        // dibayar, ditambah pesanan ini) mencapai batas, pesanan menunggu persetujuan dan stok ditahan sampai tenggat tinjau.
        $unpaid = (int) Order::where('customer_phone', $data['customer_phone'])->where('status', Order::PENDING_PAYMENT)->where('expires_at', '>', now())->sum('total');
        $needsApproval = $unpaid + $total >= OrderReviewService::setting('antifraud.approval_min_unpaid', 3000000);
        $reviewDueAt = $needsApproval || $total >= OrderReviewService::setting('antifraud.review_min_total', 1000000) ? $this->review->dueAt(now()) : null;
        $status = $needsApproval ? Order::AWAITING_APPROVAL : Order::PENDING_PAYMENT;
        $expiresAt = $needsApproval ? $reviewDueAt : now()->addMinutes(OrderReviewService::setting('checkout.reservation_minutes', 60));

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
            'ip_address' => $data['ip_address'] ?? null,
            'shipping_courier' => $rate['courier'],
            'shipping_service' => $rate['service'],
            'status' => $status,
            'payment_status' => 'pending',
            'shipping_status' => 'pending',
            'subtotal' => $summary['subtotal'],
            'discount_total' => $discount,
            'voucher_code' => $regular[0]['voucher']->code ?? null,
            'voucher_code_2' => $regular[1]['voucher']->code ?? null,
            'shipping_voucher_code' => $shippingRow['voucher']->code ?? null,
            'shipping_fee' => $rate['fee'],
            'shipping_discount' => $shippingDiscount,
            'total' => $total,
            'expires_at' => $expiresAt,
            'review_due_at' => $reviewDueAt,
        ]);

        foreach ($applied as $row) {
            $this->vouchers->record($row['voucher'], $order, $row['discount'], $data['user_id'] ?? null);
        }

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

        $order->histories()->create(['status_type' => 'order', 'to_status' => $status, 'source' => 'checkout']);

        if ($reviewDueAt) {
            $amount = 'Rp'.number_format($total, 0, ',', '.');
            $this->notifier->toRoles(['store-admin', 'owner'], $needsApproval
                ? new StaffEvent('order_approval', 'order', $order->order_number, "Pesanan {$order->order_number} ({$amount}) menunggu persetujuan", 'Belum bisa dibayar sampai disetujui Admin Toko')
                : new StaffEvent('order_review', 'order', $order->order_number, "Pesanan {$order->order_number} ({$amount}) perlu ditinjau", 'Total Rp1 juta ke atas'));
        }

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
