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
use Tests\TestCase;

/** OQ-25: hanya Admin Toko dan Super Admin yang menandai pesanan selesai, dan keduanya diberi tahu saat pesanan selesai. */
class OrderCompletedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function shippedOrder(): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 1])->assertOk();
        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = app(OrderStatusService::class)->markPaid(Order::where('public_ref', $ref)->firstOrFail());
        $order->forceFill(['status' => Order::SHIPPED, 'shipping_status' => 'shipped'])->save();
        $order->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'JT1', 'status' => 'shipped', 'shipping_fee' => 12000]);

        return $order;
    }

    public function test_only_store_admin_and_super_admin_can_mark_an_order_completed(): void
    {
        $order = $this->shippedOrder();

        foreach (['warehouse', 'customer-service', 'owner', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertForbidden();
        }
        $this->assertSame(Order::SHIPPED, $order->fresh()->status);

        $this->actingAs($this->staff('store-admin'))->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertOk();
        $this->assertSame(Order::COMPLETED, $order->fresh()->status);
    }

    public function test_super_admin_can_mark_completed_too(): void
    {
        $order = $this->shippedOrder();

        $this->actingAs($this->staff('super-admin'))->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertOk();
    }

    public function test_store_admins_and_super_admins_are_notified_except_the_actor(): void
    {
        $actor = $this->staff('store-admin');
        $otherStoreAdmin = $this->staff('store-admin');
        $superAdmin = $this->staff('super-admin');
        $warehouse = $this->staff('warehouse');
        $order = $this->shippedOrder();

        $this->actingAs($actor)->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertOk();

        $this->assertSame(1, $otherStoreAdmin->notifications()->where('data->event', 'order_completed')->count());
        $this->assertSame(1, $superAdmin->notifications()->where('data->event', 'order_completed')->count());
        $this->assertSame(0, $actor->notifications()->where('data->event', 'order_completed')->count());
        $this->assertSame(0, $warehouse->notifications()->where('data->event', 'order_completed')->count());
    }

    public function test_delivery_from_the_carrier_notifies_everyone_without_an_actor(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $superAdmin = $this->staff('super-admin');
        $order = $this->shippedOrder();
        $shipment = $order->shipment;

        app(OrderStatusService::class)->applyCarrierUpdate($shipment, 'delivered', null);

        $this->assertSame(Order::COMPLETED, $order->fresh()->status);
        $this->assertSame(1, $storeAdmin->notifications()->where('data->event', 'order_completed')->count());
        $this->assertSame(1, $superAdmin->notifications()->where('data->event', 'order_completed')->count());

        // Webhook ulang tidak mengirim pemberitahuan kedua.
        app(OrderStatusService::class)->applyCarrierUpdate($shipment->fresh(), 'delivered', null);
        $this->assertSame(1, $storeAdmin->notifications()->where('data->event', 'order_completed')->count());
    }
}
