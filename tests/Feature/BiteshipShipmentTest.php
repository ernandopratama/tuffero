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

class BiteshipShipmentTest extends TestCase
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

    private function staff(): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole('warehouse');

        return $user;
    }

    private function processingOrder(): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = Order::where('public_ref', $ref)->firstOrFail();
        $status = app(OrderStatusService::class);
        $status->markPaid($order);
        $status->startProcessing($order, $this->staff());

        return $order->fresh();
    }

    private function fakeCreate(): void
    {
        Http::fake(['api.biteship.com/v1/orders' => Http::response([
            'success' => true, 'id' => 'bt-order-1', 'status' => 'confirmed',
            'courier' => ['tracking_id' => 'trk-1', 'waybill_id' => 'WYB-1', 'company' => 'jnt', 'type' => 'ez'],
        ])]);
    }

    private function ship(Order $order, int $status = 200): void
    {
        $this->actingAs($this->staff())->postJson("/api/v1/admin/orders/{$order->id}/ship", [])->assertStatus($status);
    }

    private function webhook(array $body, ?string $secret = 'hook-secret', int $status = 200): void
    {
        $this->postJson('/api/v1/webhooks/biteship', $body, $secret ? ['X-Biteship-Secret' => $secret] : [])->assertStatus($status);
    }

    public function test_ship_without_waybill_creates_one_biteship_order_with_automatic_waybill(): void
    {
        $this->fakeCreate();
        $order = $this->processingOrder();

        $this->ship($order);
        $this->ship($order); // klik ganda: pesanan sudah SHIPPED, tidak ada order kedua

        $order->refresh();
        $this->assertSame(Order::SHIPPED, $order->status);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'provider' => 'biteship', 'provider_order_id' => 'bt-order-1', 'tracking_id' => 'trk-1', 'waybill_id' => 'WYB-1', 'status' => 'confirmed']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['reference_id'] === $order->order_number
            && $r['courier_company'] === 'jnt' && $r['courier_type'] === 'ez'
            && $r['origin_collection_method'] === 'pickup' && $r['origin_postal_code'] === 65151
            && $r['destination_contact_phone'] === '081234567890' && $r['destination_postal_code'] === 65111
            && $r['items'][0]['value'] === 50000 && $r['items'][0]['quantity'] === 2 && $r['items'][0]['weight'] === 500
            && $r->hasHeader('Authorization', 'test-key'));
    }

    public function test_biteship_failure_keeps_order_processing_and_manual_waybill_still_works(): void
    {
        Http::fake(['api.biteship.com/v1/orders' => Http::response(['success' => false, 'error' => 'Saldo tidak cukup', 'code' => 40001234], 400)]);
        $order = $this->processingOrder();

        $this->ship($order, 422);

        $this->assertSame(Order::PROCESSING, $order->fresh()->status);
        $this->assertDatabaseCount('shipments', 0);

        $this->actingAs($this->staff())->postJson("/api/v1/admin/orders/{$order->id}/ship", ['waybill_id' => 'JT123'])->assertOk();
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'provider' => 'manual', 'waybill_id' => 'JT123']);
    }

    public function test_ship_without_biteship_key_is_refused_without_any_call(): void
    {
        Http::fake();
        config(['services.biteship.key' => null]);
        $order = $this->processingOrder();

        $this->ship($order, 422);

        Http::assertNothingSent();
        $this->assertSame(Order::PROCESSING, $order->fresh()->status);
    }

    public function test_webhook_rejects_wrong_missing_or_unconfigured_secret(): void
    {
        $this->webhook(['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'delivered'], 'salah', 403);
        $this->webhook(['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'delivered'], null, 403);

        config(['services.biteship.webhook_secret' => null]);
        $this->webhook(['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'delivered'], '', 403);
    }

    public function test_webhook_install_probe_with_empty_body_gets_ok_without_changes(): void
    {
        $this->postJson('/api/v1/webhooks/biteship')->assertOk();
        $this->postJson('/api/v1/webhooks/biteship', [], ['X-Biteship-Secret' => 'salah'])->assertOk();
    }

    public function test_webhook_updates_status_and_delivered_completes_order_idempotently(): void
    {
        $this->fakeCreate();
        $order = $this->processingOrder();
        $this->ship($order);

        $this->webhook(['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'inTransit', 'courier_waybill_id' => 'WYB-1']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'in_transit']);
        $this->assertSame(Order::SHIPPED, $order->fresh()->status);

        $delivered = ['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'delivered'];
        $this->webhook($delivered);
        $this->webhook($delivered); // duplikat
        $this->webhook(['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'inTransit']); // terlambat: tidak mundur

        $order->refresh();
        $this->assertSame(Order::COMPLETED, $order->status);
        $this->assertSame('delivered', $order->shipping_status);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'delivered']);
        $this->assertNotNull($order->shipment->delivered_at);
        $this->assertSame(1, $order->histories()->where('status_type', 'order')->where('to_status', Order::COMPLETED)->count());
    }

    public function test_webhook_for_unknown_shipment_changes_nothing(): void
    {
        $this->fakeCreate();
        $order = $this->processingOrder();
        $this->ship($order);

        $this->webhook(['event' => 'order.status', 'order_id' => 'orang-lain', 'status' => 'delivered']);
        $this->webhook(['event' => 'order.price', 'order_id' => 'bt-order-1', 'status' => 'delivered']);

        $this->assertSame(Order::SHIPPED, $order->fresh()->status);
    }

    public function test_waybill_webhook_updates_waybill_only(): void
    {
        $this->fakeCreate();
        $order = $this->processingOrder();
        $this->ship($order);

        $this->webhook(['event' => 'order.waybill_id', 'order_id' => 'bt-order-1', 'courier_waybill_id' => 'NEW-9']);
        $this->webhook(['event' => 'order.waybill_id', 'order_id' => 'bt-order-1', 'courier_waybill_id' => '<script>']);

        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'waybill_id' => 'NEW-9', 'status' => 'confirmed']);
    }

    public function test_tracking_events_are_stored_and_served_from_database_after_delivery(): void
    {
        $this->fakeCreate();
        $order = $this->processingOrder();
        $this->ship($order);

        Http::fake(['api.biteship.com/v1/trackings/*' => Http::response(['success' => true, 'history' => [
            ['note' => 'Paket diterima di hub Surabaya', 'status' => 'inTransit', 'updated_at' => '2026-11-10T08:00:00+07:00'],
            ['note' => 'Paket diterima', 'status' => 'delivered', 'updated_at' => '2026-11-11T09:00:00+07:00'],
        ]])]);

        $url = "/api/v1/orders/{$order->public_ref}/tracking";
        $access = $this->withSession(["order_access.{$order->public_ref}" => true]);
        $access->getJson($url)->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.events.0.note', 'Paket diterima');
        $access->getJson($url)->assertOk(); // cache 24 jam: sekali panggilan
        $this->assertDatabaseCount('shipment_events', 2);

        $this->webhook(['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'delivered']);
        Http::fake(); // Biteship down: halaman tetap menampilkan riwayat tersimpan tanpa memanggil
        Cache::flush();
        $access->getJson($url)->assertOk()->assertJsonCount(2, 'data.events');
        Http::assertNothingSent();
    }
}
