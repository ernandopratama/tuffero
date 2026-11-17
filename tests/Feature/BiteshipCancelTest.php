<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderCancellationRequest as Request;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pembatalan order kurir Biteship saat pesanan yang sedang diproses dibatalkan (D-138, tahap 3).
 */
class BiteshipCancelTest extends TestCase
{
    use RefreshDatabase;

    private const CANCEL_URL = 'api.biteship.com/v1/orders/bt-order-1/cancel';

    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Cache::flush();
        config(['services.biteship.key' => 'test-key', 'services.biteship.origin_postal_code' => '65151', 'services.biteship.webhook_secret' => 'hook-secret']);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        $this->stock = InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** Pesanan diproses dengan resi (Biteship secara bawaan, atau manual). */
    private function processingOrder(string $provider = 'biteship'): Order
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
        $status->startProcessing($order, $this->staff('warehouse'));

        $order->shipment()->create($provider === 'biteship'
            ? ['provider' => 'biteship', 'provider_order_id' => 'bt-order-1', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'WYB-1', 'status' => 'confirmed', 'shipping_fee' => 0]
            : ['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'MAN-1', 'status' => 'ready', 'shipping_fee' => 0]);

        return $order->fresh();
    }

    private function request(Order $order): Request
    {
        $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'whatsapp'])
            ->assertCreated();

        return Request::latest('id')->firstOrFail();
    }

    private function act(string $role, string $path, int $status = 200): TestResponse
    {
        return $this->actingAs($this->staff($role))->postJson("/api/v1/admin/{$path}")->assertStatus($status);
    }

    /** Pengajuan yang sudah divalidasi CS; tinggal ACC Admin Toko (yang memicu pembatalan, D-123). */
    private function readyForFinal(Order $order): Request
    {
        $request = $this->request($order);
        $this->act('customer-service', "cancellations/{$request->id}/validate");

        return $request->fresh();
    }

    private function fakeCancel(int $status = 200, array $body = ['success' => true, 'id' => 'bt-order-1', 'status' => 'cancelled']): void
    {
        Http::fake([self::CANCEL_URL => Http::response($body, $status)]);
    }

    public function test_final_approval_cancels_the_courier_order_then_the_order(): void
    {
        $order = $this->processingOrder();
        $this->fakeCancel();
        $request = $this->readyForFinal($order);

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => str_ends_with($sent->url(), '/v1/orders/bt-order-1/cancel')
            && $sent['cancellation_reason_code'] === 'others'
            && str_contains($sent['cancellation_reason'], $request->request_number)
            && $sent->header('Authorization')[0] === 'test-key');
        $this->assertSame('cancelled', $order->shipment()->value('status'));
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        $this->assertSame(10, $this->stock->fresh()->quantity);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shipment.biteship_cancelled']);
    }

    public function test_biteship_refusal_blocks_the_approval_and_changes_nothing(): void
    {
        $order = $this->processingOrder();
        $this->fakeCancel(400, ['success' => false, 'code' => 40002053, 'error' => 'Cannot cancel order because order already picked']);
        $request = $this->readyForFinal($order);

        $response = $this->act('store-admin', "cancellations/{$request->id}/approve", 422);

        $this->assertStringContainsString('already picked', $response->json('errors.request.0'));
        $this->assertSame(Request::VALIDATED, $request->fresh()->status);
        $this->assertSame(Order::PROCESSING, $order->fresh()->status);
        $this->assertSame('confirmed', $order->shipment()->value('status'));
        $this->assertSame(8, $this->stock->fresh()->quantity);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'shipment.biteship_cancelled']);
    }

    public function test_unreachable_biteship_blocks_the_approval_and_retry_works(): void
    {
        $order = $this->processingOrder();
        Http::fake([self::CANCEL_URL => Http::sequence()->pushFailedConnection('timeout')->push(['success' => true, 'id' => 'bt-order-1', 'status' => 'cancelled'])]);
        $request = $this->readyForFinal($order);

        $this->act('store-admin', "cancellations/{$request->id}/approve", 422);

        $this->assertSame(Order::PROCESSING, $order->fresh()->status);
        $this->assertSame(Request::VALIDATED, $request->fresh()->status);

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    public function test_an_already_cancelled_courier_order_counts_as_success(): void
    {
        $order = $this->processingOrder();
        $this->fakeCancel(400, ['success' => false, 'code' => 40002053, 'error' => 'Cannot cancel order because order already cancelled']);
        $request = $this->readyForFinal($order);

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        $this->assertSame('cancelled', $order->shipment()->value('status'));
    }

    public function test_manual_waybill_and_unshipped_orders_make_no_biteship_call(): void
    {
        Http::fake();

        $manual = $this->processingOrder('manual');
        $request = $this->readyForFinal($manual);
        $this->act('store-admin', "cancellations/{$request->id}/approve");
        $this->assertSame(Order::CANCELLED, $manual->fresh()->status);

        Http::assertNothingSent();
    }

    public function test_courier_button_is_for_store_admin_and_super_admin_only_and_is_idempotent(): void
    {
        $order = $this->processingOrder();
        $this->fakeCancel();
        $request = $this->request($order);

        $this->act('store-admin', "cancellations/{$request->id}/courier-cancel", 422);
        $this->act('customer-service', "cancellations/{$request->id}/validate");

        $this->act('owner', "cancellations/{$request->id}/courier-cancel", 403);
        $this->act('customer-service', "cancellations/{$request->id}/courier-cancel", 403);
        $this->act('store-admin', "cancellations/{$request->id}/courier-cancel");

        $this->assertSame('cancelled', $order->shipment()->value('status'));
        $this->assertSame(Order::PROCESSING, $order->fresh()->status, 'Pesanan baru dibatalkan saat ACC.');
        $this->act('super-admin', "cancellations/{$request->id}/courier-cancel", 422);

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_list_flags_orders_with_an_active_courier_order(): void
    {
        $order = $this->processingOrder();
        $this->request($order);

        $row = $this->actingAs($this->staff('store-admin'))->getJson('/api/v1/admin/cancellations')->assertOk()->json('data.items.0');
        $this->assertTrue($row['courier_active']);

        $order->shipment()->update(['status' => 'cancelled']);
        $this->assertFalse($this->actingAs($this->staff('store-admin'))->getJson('/api/v1/admin/cancellations')->json('data.items.0.courier_active'));
    }

    public function test_courier_pickup_during_an_open_request_notifies_staff_instead_of_hanging_silently(): void
    {
        $order = $this->processingOrder();
        $storeAdmin = $this->staff('store-admin');
        $cs = $this->staff('customer-service');
        $this->request($order);

        $this->postJson('/api/v1/webhooks/biteship', ['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'allocated'], ['X-Biteship-Secret' => 'hook-secret'])->assertOk();

        $this->assertSame(Order::SHIPPED, $order->fresh()->status);
        $title = "Pesanan {$order->order_number} sudah diambil kurir padahal ada pengajuan pembatalan yang belum selesai. Pengajuan tidak bisa disetujui: tolak atau hubungi pelanggan";
        $this->assertContains($title, $storeAdmin->notifications->pluck('data.title')->all());
        $this->assertContains($title, $cs->notifications->pluck('data.title')->all());
    }

    public function test_courier_pickup_without_an_open_request_adds_no_cancellation_notice(): void
    {
        $order = $this->processingOrder();
        $storeAdmin = $this->staff('store-admin');

        $this->postJson('/api/v1/webhooks/biteship', ['event' => 'order.status', 'order_id' => 'bt-order-1', 'status' => 'allocated'], ['X-Biteship-Secret' => 'hook-secret'])->assertOk();

        $this->assertSame(Order::SHIPPED, $order->fresh()->status);
        $this->assertSame(0, $storeAdmin->notifications()->count());
        $this->assertSame(0, AuditLog::count());
    }
}
