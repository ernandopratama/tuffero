<?php

namespace Tests\Concurrency;

use App\Models\Order;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Bukti bahwa stok tidak minus dan tidak terjual berlebih saat banyak proses memakai stok yang sama bersamaan
 * (BR-10, TRX-04). Memanggil CheckoutService, OrderExpiryService, OrderStatusService, CancellationService, dan InventoryService
 * yang asli di MySQL 8.4 / MariaDB 10.6. Invarian dicek di ConcurrencyTestCase::assertInvariants setelah tiap putaran.
 */
class StockConcurrencyTest extends ConcurrencyTestCase
{
    /** @return array<string, array{0: int}> */
    public static function competitors(): array
    {
        return ['2 pembeli' => [2], '5 pembeli' => [5], '10 pembeli' => [10]];
    }

    /** Skenario 1: N pembeli berebut satu-satunya unit. Tepat satu menang, stok akhir 0, satu reservasi. */
    #[DataProvider('competitors')]
    public function test_last_unit_goes_to_exactly_one_buyer(int $buyers): void
    {
        $this->scenario($buyers, function (int $round) use ($buyers) {
            $this->fixtureVariant('LAST-1', 1);

            return array_map(fn (int $i) => ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s1-{$round}-{$i}", 'items' => [['LAST-1', 1]]]], range(0, $buyers - 1));
        }, function (array $results) use ($buyers) {
            $this->assertCount(1, $this->ofType($results, 'ok'), 'Tepat satu pembeli harus berhasil');
            $this->assertCount($buyers - 1, array_filter($results, fn ($r) => $this->isStockRejection($r)), 'Sisanya ditolak karena stok habis');
            $this->assertSame(1, Order::count());
            $this->assertSame(1, DB::table('stock_reservations')->where('status', 'active')->count());
            $this->assertSame([1, 1], [(int) DB::table('inventory_stocks')->value('quantity'), (int) DB::table('inventory_stocks')->value('reserved')]);
        });
    }

    /** Skenario 2: stok tersedia 3, delapan pembeli masing-masing 1 unit. Tepat tiga berhasil. */
    public function test_exactly_the_available_quantity_is_sold(): void
    {
        $this->scenario(8, function (int $round) {
            $this->fixtureVariant('STOK-3', 3);

            return array_map(fn (int $i) => ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s2-{$round}-{$i}", 'items' => [['STOK-3', 1]]]], range(0, 7));
        }, function (array $results) {
            $this->assertCount(3, $this->ofType($results, 'ok'));
            $this->assertCount(5, array_filter($results, fn ($r) => $this->isStockRejection($r)));
            $this->assertSame(3, (int) DB::table('inventory_stocks')->value('reserved'));
        });
    }

    /** Skenario 3: pesanan multi-varian dengan urutan item berbeda (A,B,C / C,B,A / ...). Tidak ada deadlock yang lolos; semuanya atau tidak sama sekali. */
    public function test_multi_variant_orders_in_opposite_item_order_do_not_deadlock(): void
    {
        $orders = [['A', 'B', 'C'], ['C', 'B', 'A'], ['B', 'C', 'A'], ['A', 'C', 'B'], ['B', 'A', 'C'], ['C', 'A', 'B']];

        $this->scenario(6, function (int $round) use ($orders) {
            foreach (['MV-A' => 'A', 'MV-B' => 'B', 'MV-C' => 'C'] as $sku => $_) {
                $this->fixtureVariant($sku, 2);
            }

            return array_map(fn (int $i) => ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s3-{$round}-{$i}", 'items' => array_map(fn ($letter) => ["MV-{$letter}", 1], $orders[$i])]], range(0, 5));
        }, function (array $results) {
            $this->assertCount(2, $this->ofType($results, 'ok'), 'Stok 2 per varian: tepat dua pesanan lengkap');
            $this->assertSame([2, 2, 2], array_map('intval', DB::table('inventory_stocks')->orderBy('id')->pluck('reserved')->all()), 'Tidak ada reservasi setengah jadi');
        });
    }

    /** Skenario 4a: checkout bersamaan dengan kedaluwarsa pesanan yang menahan satu-satunya unit. */
    public function test_checkout_racing_with_order_expiry(): void
    {
        $this->scenario(5, function (int $round) {
            $this->fixtureVariant('EXP-1', 1);
            $held = $this->placeOrder([['EXP-1', 1]], expired: true);
            $this->assertSame(Order::PENDING_PAYMENT, $held->status);

            return [
                ['action' => 'expire', 'args' => []],
                ['action' => 'expire', 'args' => []],
                ...array_map(fn (int $i) => ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s4a-{$round}-{$i}", 'items' => [['EXP-1', 1]]]], range(0, 2)),
            ];
        }, function (array $results) {
            $this->assertSame(Order::EXPIRED, Order::oldest('id')->firstOrFail()->status);
            $this->assertLessThanOrEqual(1, count($this->ofType(array_slice($results, 2), 'ok')), 'Satu unit tidak boleh dijual dua kali');
            $this->assertSame(1, DB::table('stock_movements')->where('type', 'release')->count(), 'Pelepasan terjadi sekali');
        });
    }

    /** Skenario 4b: checkout bersamaan dengan penyesuaian stok manual admin yang mengurangi stok ke 0. */
    public function test_checkout_racing_with_manual_stock_adjustment(): void
    {
        $this->scenario(3, function (int $round) {
            [$variant] = $this->fixtureVariant('ADJ-1', 1);

            return [
                ['action' => 'adjust', 'args' => ['warehouse_id' => $this->warehouse->id, 'variant_id' => $variant->id, 'type' => 'adjustment', 'change' => -1, 'actor_id' => $this->actor->id]],
                ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s4b-{$round}-a", 'items' => [['ADJ-1', 1]]]],
                ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s4b-{$round}-b", 'items' => [['ADJ-1', 1]]]],
            ];
        }, function (array $results) {
            $adjusted = $results[0]['type'] === 'ok';
            $sold = count($this->ofType(array_slice($results, 1), 'ok'));

            $this->assertFalse($adjusted && $sold > 0, 'Stok dikurangi admin dan dijual ke pembeli sekaligus (overselling)');
            $this->assertLessThanOrEqual(1, $sold);
        });
    }

    /** Skenario 4c: penyesuaian manual (stok masuk) bersamaan dengan checkout dan kedaluwarsa. Total akhir konsisten. */
    public function test_restock_expiry_and_checkout_together(): void
    {
        $this->scenario(4, function (int $round) {
            [$variant] = $this->fixtureVariant('MIX-1', 1);
            $this->placeOrder([['MIX-1', 1]], expired: true);

            return [
                ['action' => 'expire', 'args' => []],
                ['action' => 'adjust', 'args' => ['warehouse_id' => $this->warehouse->id, 'variant_id' => $variant->id, 'type' => 'restock', 'change' => 2, 'actor_id' => $this->actor->id]],
                ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s4c-{$round}-a", 'items' => [['MIX-1', 2]]]],
                ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s4c-{$round}-b", 'items' => [['MIX-1', 1]]]],
            ];
        }, function () {
            $this->assertSame(1, DB::table('stock_movements')->where('type', 'release')->count());
            $this->assertSame(3, (int) DB::table('inventory_stocks')->value('quantity'));
        });
    }

    /** Skenario 4d: pelanggan membatalkan pesanan belum dibayar bersamaan dengan kedaluwarsa pesanan itu. Satu pemenang, satu pelepasan. */
    public function test_cancel_racing_with_expiry_releases_once(): void
    {
        $this->scenario(3, function () {
            $this->fixtureVariant('CAN-1', 2);
            $held = $this->placeOrder([['CAN-1', 2]], expired: true);

            return [
                ['action' => 'cancel', 'args' => ['order_id' => $held->id]],
                ['action' => 'expire', 'args' => []],
                ['action' => 'expire', 'args' => []],
            ];
        }, function (array $results) {
            $order = Order::firstOrFail();
            $this->assertContains($order->status, [Order::CANCELLED, Order::EXPIRED]);
            $this->assertSame(1, DB::table('stock_movements')->where('type', 'release')->count(), 'Stok dilepas tepat sekali');
            $this->assertSame(0, (int) DB::table('inventory_stocks')->value('reserved'));
            // Jika pembatalan kalah, ia ditolak dengan pesan bisnis, bukan error.
            $this->assertContains($results[0]['type'], ['ok', 'validation']);
        });
    }

    /** Skenario 5: pembayaran (dikirim dua kali, seperti notifikasi ganda) bersamaan dengan kedaluwarsa pesanan yang sama. */
    public function test_payment_racing_with_expiry_has_one_winner(): void
    {
        $this->scenario(3, function () {
            $this->fixtureVariant('PAY-1', 2);
            $held = $this->placeOrder([['PAY-1', 2]], expired: true);

            return [
                ['action' => 'pay', 'args' => ['order_id' => $held->id]],
                ['action' => 'pay', 'args' => ['order_id' => $held->id]],
                ['action' => 'expire', 'args' => []],
            ];
        }, function () {
            $order = Order::firstOrFail();
            $sales = DB::table('stock_movements')->where('type', 'sale')->count();
            $releases = DB::table('stock_movements')->where('type', 'release')->count();

            $this->assertContains($order->status, [Order::PAID, Order::EXPIRED]);
            $this->assertSame($order->status === Order::PAID ? [1, 0] : [0, 1], [$sales, $releases], 'Stok terjual atau dilepas, tidak keduanya dan tidak dua kali');
            $this->assertSame([$order->status === Order::PAID ? 0 : 2, 0], [(int) DB::table('inventory_stocks')->value('quantity'), (int) DB::table('inventory_stocks')->value('reserved')]);
        });
    }

    /** Skenario 6a: klik ganda, permintaan identik (kunci idempotensi sama, nomor HP sama). Satu pesanan saja. */
    public function test_double_submit_with_same_key_creates_one_order(): void
    {
        $this->scenario(5, function (int $round) {
            $this->fixtureVariant('IDEM-1', 10);
            $phone = $this->phone();

            return array_map(fn () => ['action' => 'checkout', 'args' => ['phone' => $phone, 'key' => "s6a-{$round}", 'items' => [['IDEM-1', 2]]]], range(0, 4));
        }, function (array $results) {
            $this->assertCount(5, $this->ofType($results, 'ok'), 'Semua permintaan mendapat pesanan yang sama');
            $this->assertCount(1, array_unique(array_column(array_column($results, 'value'), 'number')));
            $this->assertSame(1, Order::count());
            $this->assertSame(2, (int) DB::table('inventory_stocks')->value('reserved'), 'Stok ditahan sekali');
        });
    }

    /** Skenario 6b: kunci idempotensi sama dari nomor HP berbeda, melewati kunci per-nomor dan menguji indeks unik di database. */
    public function test_same_idempotency_key_from_different_phones_creates_one_order(): void
    {
        $this->scenario(5, function (int $round) {
            $this->fixtureVariant('IDEM-2', 10);

            return array_map(fn () => ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s6b-{$round}", 'items' => [['IDEM-2', 2]]]], range(0, 4));
        }, function (array $results) {
            $this->assertCount(5, $this->ofType($results, 'ok'));
            $this->assertSame(1, Order::count());
            $this->assertSame(2, (int) DB::table('inventory_stocks')->value('reserved'));
        });
    }

    /**
     * Skenario 7: voucher berkuota 1 dipegang pesanan yang kedaluwarsa. Checkout berpakaian voucher (kunci voucher lalu stok)
     * berlomba dengan kedaluwarsa (kunci stok lalu voucher): urutan kunci berlawanan, rawan deadlock. Kuota tidak boleh
     * terlewati dan used_count harus sama dengan penukaran yang belum dilepas.
     */
    public function test_voucher_checkout_racing_with_expiry_keeps_quota_and_does_not_deadlock(): void
    {
        $this->scenario(6, function (int $round) {
            $this->fixtureVariant('VCH-1', 4);
            Voucher::create(['code' => 'UJI5', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0, 'is_active' => true, 'quota' => 1, 'per_customer_limit' => null]);
            $this->placeOrder([['VCH-1', 1]], voucher: 'UJI5', expired: true);

            return [
                ['action' => 'expire', 'args' => []],
                ['action' => 'expire', 'args' => []],
                ...array_map(fn (int $i) => ['action' => 'checkout', 'args' => ['phone' => $this->phone(), 'key' => "s7-{$round}-{$i}", 'items' => [['VCH-1', 1]], 'voucher_code' => 'UJI5']], range(0, 3)),
            ];
        }, function (array $results) {
            $voucher = Voucher::firstOrFail();
            $live = DB::table('voucher_redemptions')->whereNull('released_at')->count();

            $this->assertLessThanOrEqual(1, $live, 'Kuota voucher 1 terlewati');
            $this->assertSame($live, (int) $voucher->used_count, 'used_count tidak sama dengan penukaran yang belum dilepas');
            $this->assertSame(Order::EXPIRED, Order::oldest('id')->firstOrFail()->status);

            foreach (array_slice($results, 2) as $result) {
                $this->assertTrue($result['type'] === 'ok' || ($result['type'] === 'validation' && isset($result['errors']['voucher_code'])), 'Hasil checkout voucher tak terduga: '.json_encode($result));
            }
        });
    }
}
