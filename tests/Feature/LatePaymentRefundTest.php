<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatePaymentRefundTest extends TestCase
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

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
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

    /** Pesanan yang sudah ditutup tetapi dananya masuk (settlement). */
    private function lateOrder(string $status = Order::EXPIRED, string $payment = 'expired'): Order
    {
        $order = $this->order(['status' => $status, 'payment_status' => $payment]);
        PaymentEvent::create(['order_id' => $order->id, 'transaction_id' => 'trx-'.$order->id, 'transaction_status' => 'settlement', 'gross_amount' => (int) $order->total, 'payload' => []]);

        return $order;
    }

    private function mark(User $user, Order $order, ?string $note = 'Transfer ke rekening pemesan, ref 123456')
    {
        return $this->actingAs($user)->postJson("/api/v1/admin/orders/{$order->id}/late-payment-refunded", $note === null ? [] : ['note' => $note]);
    }

    public function test_store_admin_marks_the_refund_with_a_note_and_nothing_else_changes(): void
    {
        $order = $this->lateOrder();
        $stock = InventoryStock::first()->only(['quantity', 'reserved']);
        $admin = $this->staff('store-admin');

        $this->mark($admin, $order)->assertOk();

        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame(Order::EXPIRED, $order->status, 'status pesanan tidak berubah');
        $this->assertEquals($stock, InventoryStock::first()->only(['quantity', 'reserved']), 'stok tidak disentuh');

        $history = $order->histories()->where('status_type', 'payment')->latest('id')->first();
        $this->assertSame(['expired', 'refunded', 'admin', $admin->id, 'Transfer ke rekening pemesan, ref 123456'], [$history->from_status, $history->to_status, $history->source, $history->actor_user_id, $history->reason]);

        $audit = AuditLog::where('action', 'order.late_payment_refunded')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('Transfer ke rekening pemesan, ref 123456', $audit->changes['note']);
    }

    public function test_a_cancelled_before_payment_order_with_late_money_can_also_be_marked(): void
    {
        $order = $this->lateOrder(Order::CANCELLED, 'cancelled');

        $this->mark($this->staff('store-admin'), $order)->assertOk();

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    public function test_the_note_is_required_and_cleaned(): void
    {
        $order = $this->lateOrder();
        $admin = $this->staff('store-admin');

        $this->mark($admin, $order, null)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->mark($admin, $order, '   ')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->mark($admin, $order, '<b></b>')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->mark($admin, $order, str_repeat('a', 501))->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->assertSame('expired', $order->fresh()->payment_status);

        $this->mark($admin, $order, '<b>Ref</b> 99')->assertOk();
        $this->assertSame('Ref 99', $order->histories()->where('to_status', 'refunded')->value('reason'));
    }

    public function test_only_orders_with_late_money_can_be_marked_and_only_once(): void
    {
        $admin = $this->staff('store-admin');

        $unpaidExpired = $this->order(['status' => Order::EXPIRED, 'payment_status' => 'expired']);
        $pending = $this->order();
        $inRefundFlow = $this->lateOrder(Order::CANCELLED, 'refund_pending');
        foreach ([$unpaidExpired, $pending, $inRefundFlow] as $order) {
            $before = $order->only(['status', 'payment_status']);
            $this->mark($admin, $order)->assertUnprocessable()->assertJsonValidationErrors('order');
            $this->assertEquals($before, $order->fresh()->only(['status', 'payment_status']));
        }

        $late = $this->lateOrder();
        $this->mark($admin, $late)->assertOk();
        $this->mark($admin, $late)->assertUnprocessable();
        $this->assertSame(1, $late->histories()->where('to_status', 'refunded')->count());
        $this->assertSame(1, AuditLog::where('action', 'order.late_payment_refunded')->count());
    }

    public function test_only_store_admin_and_super_admin_can_mark_it_and_mfa_is_required(): void
    {
        $order = $this->lateOrder();

        $this->postJson("/api/v1/admin/orders/{$order->id}/late-payment-refunded", ['note' => 'x'])->assertUnauthorized();

        foreach (['owner', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->mark($this->staff($role), $order)->assertForbidden();
        }
        $this->mark($this->staff('store-admin', ['mfa_totp_confirmed_at' => null]), $order)->assertForbidden();
        $this->assertSame('expired', $order->fresh()->payment_status);

        $this->mark($this->staff('super-admin'), $order)->assertOk();
    }

    public function test_payments_list_flags_only_the_orders_that_need_a_refund_and_clears_after_marking(): void
    {
        $late = $this->lateOrder();
        $plain = $this->order(['status' => Order::EXPIRED, 'payment_status' => 'expired']);
        $admin = $this->staff('store-admin');

        $flags = fn () => collect($this->actingAs($admin)->getJson('/api/v1/admin/payments')->assertOk()->json('data.items'))->pluck('needs_refund', 'id');

        $this->assertTrue($flags()[$late->id]);
        $this->assertFalse($flags()[$plain->id]);

        $this->mark($admin, $late)->assertOk();
        $this->assertFalse($flags()[$late->id]);
    }

    public function test_dashboard_and_stuck_order_check_stop_flagging_it_after_marking(): void
    {
        $late = $this->lateOrder();
        $attention = fn (string $role) => collect($this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.attention'))->keyBy('key');
        $check = fn () => collect($this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/system-health')->json('data.stuck_orders'))->firstWhere('key', 'late_payment');

        $this->assertSame(1, $attention('store-admin')['late_payment_refund']['count']);
        $this->assertSame('AdminPayments', $attention('store-admin')['late_payment_refund']['to']);
        // Pemilik hanya memantau: peringatan tampil, tetapi menandai dikembalikan tetap izin `orders.cancel` (di bawah).
        $this->assertSame(1, $attention('owner')['late_payment_refund']['count']);
        $this->assertSame('AdminPayments', $attention('owner')['late_payment_refund']['to']);
        $this->assertArrayNotHasKey('late_payment_refund', $attention('customer-service')->all());
        $this->assertArrayNotHasKey('late_payment_refund', $attention('warehouse')->all());
        $this->assertArrayNotHasKey('late_payment_refund', $attention('marketing')->all());
        $this->assertSame('bad', $check()['status']);
        $this->mark($this->staff('owner'), $late)->assertForbidden();

        $this->mark($this->staff('store-admin'), $late)->assertOk();

        $this->assertArrayNotHasKey('late_payment_refund', $attention('store-admin')->all());
        $this->assertArrayNotHasKey('late_payment_refund', $attention('owner')->all());
        $this->assertSame('ok', $check()['status']);
    }
}
