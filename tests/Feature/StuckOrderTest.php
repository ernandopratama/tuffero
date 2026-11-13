<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StuckOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 100]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function order(array $attributes = []): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 1])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = Order::where('public_ref', $ref)->firstOrFail();
        $order->forceFill($attributes)->save();

        return $order;
    }

    /** @return array<string, array<string, mixed>> */
    private function checks(): array
    {
        return collect($this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/system-health')->assertOk()->json('data.stuck_orders'))->keyBy('key')->all();
    }

    public function test_nothing_is_flagged_when_there_are_no_orders(): void
    {
        foreach ($this->checks() as $check) {
            $this->assertSame('ok', $check['status'], $check['key']);
            $this->assertSame(0, $check['count']);
        }
    }

    public function test_paid_orders_not_processed_are_a_warning_then_a_problem(): void
    {
        $this->order(['status' => Order::PAID, 'paid_at' => now()->subHours(2)]);
        $this->assertSame('ok', $this->checks()['paid_unprocessed']['status']);

        $this->order(['status' => Order::PAID, 'paid_at' => now()->subHours(30)]);
        $check = $this->checks()['paid_unprocessed'];
        $this->assertSame('warn', $check['status']);
        $this->assertSame(1, $check['count']);

        $this->order(['status' => Order::PAID, 'paid_at' => now()->subHours(80)]);
        $check = $this->checks()['paid_unprocessed'];
        $this->assertSame('bad', $check['status']);
        $this->assertSame(2, $check['count']);
    }

    public function test_pending_orders_past_their_deadline_point_to_a_stopped_scheduler(): void
    {
        $this->order(['expires_at' => now()->subMinutes(5)]);
        $this->assertSame('ok', $this->checks()['expired_open']['status']);

        $this->order(['expires_at' => now()->subMinutes(30)]);
        $check = $this->checks()['expired_open'];
        $this->assertSame('bad', $check['status']);
        $this->assertSame(1, $check['count']);
        $this->assertStringContainsString('penjadwal', $check['detail']);
    }

    public function test_money_received_for_a_closed_order_is_flagged_until_it_enters_the_refund_flow(): void
    {
        $late = $this->order(['status' => Order::EXPIRED, 'payment_status' => 'expired']);
        $refunded = $this->order(['status' => Order::CANCELLED, 'payment_status' => 'refund_pending']);
        $unpaid = $this->order(['status' => Order::EXPIRED, 'payment_status' => 'expired']);

        foreach ([$late, $refunded] as $order) {
            PaymentEvent::create(['order_id' => $order->id, 'transaction_id' => 'trx-'.$order->id, 'transaction_status' => 'settlement', 'gross_amount' => 50000, 'payload' => []]);
        }
        PaymentEvent::create(['order_id' => $unpaid->id, 'transaction_id' => 'trx-'.$unpaid->id, 'transaction_status' => 'pending', 'gross_amount' => 50000, 'payload' => []]);

        $check = $this->checks()['late_payment'];

        $this->assertSame('bad', $check['status']);
        $this->assertSame(1, $check['count']);
    }

    public function test_processing_orders_without_a_waybill_are_flagged_by_age(): void
    {
        $stale = $this->order(['status' => Order::PROCESSING]);
        $stale->histories()->create(['status_type' => 'order', 'from_status' => Order::PAID, 'to_status' => Order::PROCESSING, 'source' => 'admin']);
        DB::table('order_status_histories')->where('order_id', $stale->id)->update(['created_at' => now()->subHours(60)]);

        $withWaybill = $this->order(['status' => Order::PROCESSING]);
        $withWaybill->histories()->create(['status_type' => 'order', 'from_status' => Order::PAID, 'to_status' => Order::PROCESSING, 'source' => 'admin']);
        DB::table('order_status_histories')->where('order_id', $withWaybill->id)->update(['created_at' => now()->subHours(60)]);
        $withWaybill->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'RESI123', 'status' => 'confirmed', 'shipping_fee' => 10000]);

        $check = $this->checks()['no_waybill'];
        $this->assertSame('warn', $check['status']);
        $this->assertSame(1, $check['count']);

        DB::table('order_status_histories')->where('order_id', $stale->id)->update(['created_at' => now()->subHours(130)]);
        $this->assertSame('bad', $this->checks()['no_waybill']['status']);
    }

    public function test_shipped_orders_without_arrival_after_two_weeks_are_a_warning(): void
    {
        $slow = $this->order(['status' => Order::SHIPPED]);
        $slow->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'RESI1', 'status' => 'in_transit', 'shipping_fee' => 10000, 'shipped_at' => now()->subDays(20)]);

        $delivered = $this->order(['status' => Order::SHIPPED]);
        $delivered->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'RESI2', 'status' => 'delivered', 'shipping_fee' => 10000, 'shipped_at' => now()->subDays(20), 'delivered_at' => now()->subDays(18)]);

        $recent = $this->order(['status' => Order::SHIPPED]);
        $recent->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'RESI3', 'status' => 'in_transit', 'shipping_fee' => 10000, 'shipped_at' => now()->subDays(3)]);

        $check = $this->checks()['shipped_stale'];

        $this->assertSame('warn', $check['status']);
        $this->assertSame(1, $check['count']);
    }

    public function test_checks_only_read_and_never_change_orders(): void
    {
        $order = $this->order(['status' => Order::PAID, 'paid_at' => now()->subHours(100)]);
        $before = $order->fresh()->only(['status', 'payment_status', 'shipping_status', 'updated_at']);

        $this->checks();

        $this->assertEquals($before, $order->fresh()->only(['status', 'payment_status', 'shipping_status', 'updated_at']));
    }

    public function test_dashboard_flags_stuck_orders_to_super_admin_only(): void
    {
        $attention = fn (string $role) => collect($this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertArrayNotHasKey('stuck_orders', $attention('super-admin')->all());

        $this->order(['status' => Order::PAID, 'paid_at' => now()->subHours(100)]);

        $item = $attention('super-admin')['stuck_orders'];
        $this->assertSame(1, $item['count']);
        $this->assertSame('bad', $item['tone']);
        $this->assertArrayNotHasKey('stuck_orders', $attention('owner')->all());
    }
}
