<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Proses Pesanan": resi Biteship atau manual, Dikirim baru setelah paket diambil kurir, dan data label. */
class ArrangeOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Cache::flush();
        config(['services.biteship.key' => 'test-key', 'services.biteship.origin_postal_code' => '65151', 'services.biteship.webhook_secret' => 'hook-secret']);

        Warehouse::where('code', 'MLG')->update(['address' => 'Jl. Gudang 1, Malang', 'postal_code' => '65151', 'phone' => '081252507880']);
        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function staff(string $role = 'warehouse'): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function paidOrder(): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = Order::where('public_ref', $ref)->firstOrFail();
        app(OrderStatusService::class)->markPaid($order);

        return $order->fresh();
    }

    private function fakeCreate(): void
    {
        Http::fake(['api.biteship.com/v1/orders' => Http::response([
            'success' => true, 'id' => 'bt-order-1', 'status' => 'confirmed',
            'courier' => ['tracking_id' => 'trk-1', 'waybill_id' => 'WYB-1', 'company' => 'jnt', 'type' => 'ez'],
        ])]);
    }

    private function arrange(Order $order, array $body, int $status = 200, string $role = 'warehouse'): void
    {
        $this->actingAs($this->staff($role))->postJson("/api/v1/admin/orders/{$order->id}/arrange", $body)->assertStatus($status);
    }

    private function webhook(string $status): void
    {
        $this->postJson('/api/v1/webhooks/biteship', ['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => $status], ['X-Biteship-Secret' => 'hook-secret'])->assertOk();
    }

    public function test_arrange_with_biteship_creates_waybill_and_keeps_order_processing(): void
    {
        $this->fakeCreate();
        $order = $this->paidOrder();

        $this->arrange($order, ['method' => 'biteship']);
        $this->arrange($order, ['method' => 'biteship'], 422); // klik ganda: sudah punya resi

        $order->refresh();
        $this->assertSame(Order::PROCESSING, $order->status);
        $this->assertSame('packing', $order->shipping_status);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'provider' => 'biteship', 'waybill_id' => 'WYB-1', 'status' => 'confirmed', 'shipped_at' => null]);
        Http::assertSentCount(1);
    }

    public function test_biteship_failure_leaves_order_paid_without_shipment(): void
    {
        Http::fake(['api.biteship.com/v1/orders' => Http::response(['success' => false, 'error' => 'Saldo tidak cukup', 'code' => 40001234], 400)]);
        $order = $this->paidOrder();

        $this->arrange($order, ['method' => 'biteship'], 422);

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_manual_arrange_needs_waybill_and_stores_note_then_mark_shipped(): void
    {
        $order = $this->paidOrder();

        $this->arrange($order, ['method' => 'manual'], 422);
        $this->arrange($order, ['method' => 'manual', 'waybill_id' => 'bad waybill!'], 422);
        $this->assertDatabaseCount('shipments', 0);

        $this->arrange($order, ['method' => 'manual', 'waybill_id' => 'JT123', 'note' => 'Dititip <b>satpam</b>']);

        $order->refresh();
        $this->assertSame(Order::PROCESSING, $order->status);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'provider' => 'manual', 'waybill_id' => 'JT123', 'note' => 'Dititip satpam', 'status' => 'ready', 'shipped_at' => null]);

        $this->actingAs($this->staff())->postJson("/api/v1/admin/orders/{$order->id}/mark-shipped")->assertOk();

        $order->refresh();
        $this->assertSame(Order::SHIPPED, $order->status);
        $this->assertNotNull($order->shipment->shipped_at);
        $this->assertSame('shipped', $order->shipment->status);
    }

    public function test_mark_shipped_needs_a_shipment_first(): void
    {
        $order = $this->paidOrder();
        app(OrderStatusService::class)->startProcessing($order, $this->staff());

        $this->actingAs($this->staff())->postJson("/api/v1/admin/orders/{$order->id}/mark-shipped")->assertUnprocessable();
        $this->assertSame(Order::PROCESSING, $order->fresh()->status);
    }

    public function test_only_staff_who_manage_shipments_can_arrange(): void
    {
        $order = $this->paidOrder();

        $this->arrange($order, ['method' => 'manual', 'waybill_id' => 'JT1'], 403, 'customer-service');
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_courier_pickup_webhook_marks_processing_order_shipped_and_delivery_completes_it(): void
    {
        $this->fakeCreate();
        $order = $this->paidOrder();
        $this->arrange($order, ['method' => 'biteship']);

        $this->webhook('allocated');
        $this->webhook('picking_up');
        $this->assertSame(Order::PROCESSING, $order->fresh()->status);

        $this->webhook('picked');
        $order->refresh();
        $this->assertSame(Order::SHIPPED, $order->status);
        $this->assertNotNull($order->shipment->shipped_at);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'to_status' => Order::SHIPPED, 'source' => 'biteship_webhook']);

        $this->webhook('delivered');
        $this->assertSame(Order::COMPLETED, $order->fresh()->status);
    }

    public function test_label_needs_waybill_and_hides_prices_and_email(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->staff());

        $this->getJson("/api/v1/admin/orders/{$order->id}/label")->assertUnprocessable();

        $this->arrange($order, ['method' => 'manual', 'waybill_id' => 'JT123', 'note' => 'Hati-hati']);

        $response = $this->actingAs($this->staff())->getJson("/api/v1/admin/orders/{$order->id}/label")
            ->assertOk()
            ->assertJsonPath('data.waybill_id', 'JT123')
            ->assertJsonPath('data.note', 'Hati-hati')
            ->assertJsonPath('data.courier', 'JNT EZ')
            ->assertJsonPath('data.recipient.name', 'Budi Uji')
            ->assertJsonPath('data.weight_gram', 1000)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.website', 'https://tuffero.com')
            ->assertJsonCount(25, 'data.qr');

        $this->assertMatchesRegularExpression('/^[01]{25}$/', $response->json('data.qr.0'));

        $body = $response->getContent();
        $this->assertStringNotContainsString('budi@example.com', $body);
        $this->assertStringNotContainsString('50000', $body);
        $this->assertStringNotContainsString('unit_price', $body);
    }
}
