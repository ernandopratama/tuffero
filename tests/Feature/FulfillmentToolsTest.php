<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Alat Gudang di Pengemasan dan Stok: filter, urutan, daftar ambil massal, filter stok menipis, dan tautan dasbor. */
class FulfillmentToolsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'MLG')->firstOrFail();

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $this->variant($product, 'NAS-01', 'Standar', 100);
        $this->variant($product, 'KAS-01', 'Kastengel', 100);
    }

    private function variant(Product $product, string $sku, string $name, int $stock): ProductVariant
    {
        $variant = $product->variants()->create(['sku' => $sku, 'name' => $name, 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => $stock]);

        return $variant;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @param  array<string, int>  $items sku => jumlah */
    private function paidOrder(array $items, string $courier = 'jnt'): Order
    {
        foreach ($items as $sku => $quantity) {
            $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => $quantity])->assertOk();
        }

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Rahasia',
            'customer_email' => 'budi.rahasia@example.com',
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = app(OrderStatusService::class)->markPaid(Order::where('public_ref', $ref)->firstOrFail());
        $order->update(['shipping_courier' => $courier, 'paid_at' => now()->subMinutes(10 - Order::count())]);

        return $order->refresh();
    }

    /** @return list<string> */
    private function fulfillmentNumbers(string $query = ''): array
    {
        return collect($this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/fulfillment'.$query)->assertOk()->json('data.items'))->pluck('order_number')->all();
    }

    // ---------- Pengemasan: filter dan urutan ----------

    public function test_fulfillment_filters_by_courier_and_waybill_and_sorts(): void
    {
        $first = $this->paidOrder(['NAS-01' => 2], 'jnt');
        $second = $this->paidOrder(['KAS-01' => 1], 'sicepat');
        $second->shipment()->create(['provider' => 'manual', 'courier_code' => 'sicepat', 'service_code' => 'reg', 'shipping_fee' => 0, 'waybill_id' => 'RESI123', 'status' => 'created']);

        $this->assertSame([$first->order_number, $second->order_number], $this->fulfillmentNumbers(), 'Bawaan: terlama dulu.');
        $this->assertSame([$second->order_number, $first->order_number], $this->fulfillmentNumbers('?sort=newest'));

        $this->assertSame([$second->order_number], $this->fulfillmentNumbers('?courier=sicepat'));
        $this->assertSame([$first->order_number], $this->fulfillmentNumbers('?courier=jnt'));
        $this->assertSame([$first->order_number, $second->order_number], $this->fulfillmentNumbers('?courier=nonsense'), 'Kurir tak dikenal diabaikan.');

        $this->assertSame([$second->order_number], $this->fulfillmentNumbers('?waybill=with'));
        $this->assertSame([$first->order_number], $this->fulfillmentNumbers('?waybill=without'));
    }

    // ---------- Daftar ambil ----------

    public function test_pick_list_sums_items_across_orders_without_prices_or_buyer_data(): void
    {
        $a = $this->paidOrder(['NAS-01' => 2, 'KAS-01' => 1]);
        $b = $this->paidOrder(['NAS-01' => 3]);

        $response = $this->actingAs($this->staff('warehouse'))->getJson("/api/v1/admin/fulfillment/pick-list?ids={$a->id},{$b->id}")->assertOk();

        $lines = collect($response->json('data.lines'))->keyBy('sku');
        $this->assertSame(5, $lines['NAS-01']['quantity']);
        $this->assertSame(2, $lines['NAS-01']['orders']);
        $this->assertSame(1, $lines['KAS-01']['quantity']);
        $response->assertJsonPath('data.total_quantity', 6)->assertJsonPath('data.skipped', 0)->assertJsonCount(2, 'data.orders');

        $json = $response->getContent();
        foreach (['Budi Rahasia', 'budi.rahasia@example.com', '081234567890', 'Jl. Uji 1', 'price'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $json);
        }
    }

    public function test_pick_list_skips_orders_that_are_no_longer_open_and_validates_ids(): void
    {
        $open = $this->paidOrder(['NAS-01' => 1]);
        $done = $this->paidOrder(['NAS-01' => 1]);
        $done->forceFill(['status' => Order::COMPLETED])->save();
        $warehouse = $this->staff('warehouse');

        $this->actingAs($warehouse)->getJson("/api/v1/admin/fulfillment/pick-list?ids={$open->id},{$done->id}")->assertOk()
            ->assertJsonPath('data.skipped', 1)->assertJsonCount(1, 'data.orders');

        $this->actingAs($warehouse)->getJson('/api/v1/admin/fulfillment/pick-list')->assertJsonValidationErrors('ids');
        $this->actingAs($warehouse)->getJson('/api/v1/admin/fulfillment/pick-list?ids=abc,0')->assertJsonValidationErrors('ids');
        $this->actingAs($warehouse)->getJson('/api/v1/admin/fulfillment/pick-list?ids[]=1')->assertJsonValidationErrors('ids');
        $this->actingAs($warehouse)->getJson('/api/v1/admin/fulfillment/pick-list?ids='.implode(',', range(1, 51)))->assertJsonValidationErrors('ids');
    }

    /** @return array<string, array{string}> */
    public static function rolesWithoutFulfillment(): array
    {
        return array_map(fn (string $role) => [$role], ['owner', 'store-admin', 'customer-service', 'marketing']);
    }

    #[DataProvider('rolesWithoutFulfillment')]
    public function test_pick_list_needs_the_fulfillment_permission(string $role): void
    {
        $order = $this->paidOrder(['NAS-01' => 1]);

        $this->actingAs($this->staff($role))->getJson("/api/v1/admin/fulfillment/pick-list?ids={$order->id}")->assertForbidden();
    }

    // ---------- Stok: filter menipis dan habis ----------

    public function test_stock_level_filter_matches_the_dashboard_rules(): void
    {
        $product = Product::create(['name' => 'Kue', 'slug' => 'kue', 'is_published' => true]);
        $low = $this->variant($product, 'LOW-01', 'Tipis', 6);       // tersedia 6, batas 10: menipis
        $out = $this->variant($product, 'OUT-01', 'Habis', 0);        // tersedia 0: habis
        $own = $this->variant($product, 'OWN-01', 'Batas sendiri', 6); // tersedia 6, batas varian 3: aman
        $own->update(['low_stock_threshold' => 3]);
        $inactive = $this->variant($product, 'OFF-01', 'Nonaktif', 0);
        $inactive->update(['is_active' => false]);
        $warehouse = $this->staff('warehouse');

        $skus = fn (string $level) => collect($this->actingAs($warehouse)->getJson("/api/v1/admin/stock?per_page=100&level={$level}")->assertOk()->json('data.items'))->pluck('sku')->sort()->values()->all();

        $this->assertSame(['LOW-01'], $skus('low'));
        $this->assertSame(['OUT-01'], $skus('out'));
        $this->assertSame(['LOW-01', 'OUT-01'], $skus('alert'));

        $own->update(['low_stock_threshold' => 8]);
        $this->assertSame(['LOW-01', 'OWN-01'], $skus('low'));

        $this->actingAs($warehouse)->getJson('/api/v1/admin/stock?per_page=100&level=hack')->assertOk()->assertJsonPath('data.meta.total', 6);
    }

    // ---------- Dasbor ----------

    public function test_warehouse_dashboard_lists_link_to_the_full_pages(): void
    {
        $lists = collect($this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.lists'))->keyBy('key');

        $this->assertSame('AdminFulfillment', $lists['pack_queue']['more']['to']);
        $this->assertSame('AdminStock', $lists['stock_alerts']['more']['to']);
        $this->assertSame('alert', $lists['stock_alerts']['more']['query']['level']);
    }
}
