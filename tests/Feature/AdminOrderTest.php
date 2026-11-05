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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

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

    private function order(int $quantity = 2): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => $quantity])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        return Order::where('public_ref', $ref)->firstOrFail();
    }

    private function paidOrder(int $quantity = 2): Order
    {
        return app(OrderStatusService::class)->markPaid($this->order($quantity));
    }

    private function cancellation(Order $order): Request
    {
        $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'whatsapp'])
            ->assertCreated();

        return Request::latest('id')->firstOrFail();
    }

    private function act(string $role, string $path, array $body = [], int $status = 200): void
    {
        $this->actingAs($this->staff($role))->postJson("/api/v1/admin/{$path}", $body)->assertStatus($status);
    }

    /** @return array<string, array{string, string, int}> */
    public static function readAccessProvider(): array
    {
        return [
            'admin toko pesanan' => ['store-admin', 'orders', 200],
            'cs pesanan' => ['customer-service', 'orders', 200],
            'pemilik pesanan' => ['owner', 'orders', 200],
            'gudang bukan pesanan penuh' => ['warehouse', 'orders', 403],
            'marketing bukan pesanan' => ['marketing', 'orders', 403],
            'gudang pengemasan' => ['warehouse', 'fulfillment', 200],
            'admin toko bukan pengemasan' => ['store-admin', 'fulfillment', 403],
            'cs bukan pengemasan' => ['customer-service', 'fulfillment', 403],
            'gudang pengiriman' => ['warehouse', 'shipments', 200],
            'cs lihat pengiriman' => ['customer-service', 'shipments', 200],
            'pemilik lihat pengiriman' => ['owner', 'shipments', 200],
            'marketing bukan pengiriman' => ['marketing', 'shipments', 403],
            'admin toko pembayaran' => ['store-admin', 'payments', 200],
            'pemilik pembayaran' => ['owner', 'payments', 200],
            'gudang bukan pembayaran' => ['warehouse', 'payments', 403],
            'cs bukan pembayaran' => ['customer-service', 'payments', 403],
            'cs pembatalan' => ['customer-service', 'cancellations', 200],
            'pemilik pembatalan' => ['owner', 'cancellations', 200],
            'gudang bukan pembatalan' => ['warehouse', 'cancellations', 403],
        ];
    }

    #[DataProvider('readAccessProvider')]
    public function test_read_access(string $role, string $path, int $status): void
    {
        $this->actingAs($this->staff($role))->getJson("/api/v1/admin/{$path}")->assertStatus($status);
    }

    public function test_fulfillment_hides_price_payment_and_email(): void
    {
        $this->paidOrder();

        $response = $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/fulfillment')
            ->assertOk()
            ->assertJsonPath('data.items.0.shipping_address.recipient', 'Budi Uji')
            ->assertJsonPath('data.items.0.items.0.quantity', 2);

        foreach (['subtotal', 'shipping_fee', '"total":100', 'unit_price', 'line_total', 'payment_status', 'budi@example.com'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }
    }

    public function test_unpaid_order_is_not_in_fulfillment(): void
    {
        $this->order();

        $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/fulfillment')->assertJsonPath('data.meta.total', 0);
    }

    public function test_payment_list_has_no_customer_data_and_no_write_route(): void
    {
        $order = $this->paidOrder();

        $response = $this->actingAs($this->staff('store-admin'))->getJson('/api/v1/admin/payments')->assertJsonPath('data.items.0.payment_status', 'paid');

        $this->assertStringNotContainsString('Budi', $response->getContent());
        $this->postJson("/api/v1/admin/orders/{$order->id}/pay")->assertStatus(405);
        $this->patchJson("/api/v1/admin/orders/{$order->id}", ['payment_status' => 'paid', 'status' => 'paid'])->assertStatus(405);
    }

    public function test_mark_paid_consumes_stock_once_and_ignores_other_status(): void
    {
        $order = $this->order();
        $service = app(OrderStatusService::class);

        $service->markPaid($order);
        $service->markPaid($order);

        $this->stock->refresh();
        $this->assertSame(8, $this->stock->quantity);
        $this->assertSame(0, $this->stock->reserved);
        $this->assertSame('consumed', $order->reservations()->value('status'));
        $this->assertSame(1, $this->stock->movements()->where('type', 'sale')->count());
    }

    public function test_expired_order_cannot_become_paid(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => Order::EXPIRED])->save();

        $this->assertSame(Order::EXPIRED, app(OrderStatusService::class)->markPaid($order)->status);
    }

    public function test_full_status_flow_with_history(): void
    {
        $order = $this->paidOrder();
        $user = $this->staff('warehouse');
        $this->actingAs($user);

        $this->postJson("/api/v1/admin/orders/{$order->id}/ship", ['waybill_id' => 'JT123'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertUnprocessable();

        $this->postJson("/api/v1/admin/orders/{$order->id}/process")->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order->id}/process")->assertUnprocessable();
        $this->postJson("/api/v1/admin/orders/{$order->id}/ship", ['waybill_id' => 'JT123'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order->id}/ship", ['waybill_id' => 'JT124'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertOk();

        $order->refresh();
        $this->assertSame(Order::COMPLETED, $order->status);
        $this->assertSame('delivered', $order->shipping_status);
        $this->assertSame('JT124', $order->shipment->waybill_id);
        $this->assertSame('manual', $order->shipment->provider);
        $this->assertSame($user->id, $order->histories()->where('to_status', Order::SHIPPED)->value('actor_user_id'));
        $this->assertSame(1, AuditLog::where('action', 'shipment.waybill_corrected')->count());
    }

    public function test_unpaid_order_cannot_be_processed(): void
    {
        $order = $this->order();

        $this->actingAs($this->staff('store-admin'))->postJson("/api/v1/admin/orders/{$order->id}/process")->assertUnprocessable();
    }

    /** @return array<string, array{string, string}> */
    public static function writeForbiddenProvider(): array
    {
        return [
            'cs proses' => ['customer-service', 'process'],
            'pemilik proses' => ['owner', 'process'],
            'marketing proses' => ['marketing', 'process'],
            'cs resi' => ['customer-service', 'ship'],
            'pemilik resi' => ['owner', 'ship'],
            'pemilik selesai' => ['owner', 'complete'],
        ];
    }

    #[DataProvider('writeForbiddenProvider')]
    public function test_write_forbidden(string $role, string $action): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->staff($role))->postJson("/api/v1/admin/orders/{$order->id}/{$action}", ['waybill_id' => 'X1'])->assertForbidden();
    }

    public function test_waybill_format_is_validated(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->staff('warehouse'))->postJson("/api/v1/admin/orders/{$order->id}/ship", ['waybill_id' => '<script>'])->assertUnprocessable();
    }

    public function test_customer_request_requires_access_and_contact_url_has_only_request_number(): void
    {
        $order = $this->order();
        $this->flushSession();

        $this->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'x', 'channel' => 'whatsapp'])->assertNotFound();

        $url = $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'whatsapp'])
            ->assertCreated()
            ->json('data.contact_url');

        $this->assertStringStartsWith('https://wa.me/', $url);
        $this->assertStringNotContainsString('Budi', urldecode($url));
        $this->assertStringNotContainsString('budi@example.com', urldecode($url));

        $this->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Lagi', 'channel' => 'email'])->assertUnprocessable();
        $this->getJson("/api/v1/orders/{$order->public_ref}")->assertJsonPath('data.can_cancel', false);
    }

    public function test_shipped_order_cannot_request_cancellation(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['status' => Order::SHIPPED])->save();

        $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'x', 'channel' => 'email'])
            ->assertUnprocessable();
    }

    public function test_unpaid_cancellation_finishes_at_store_approval_and_releases_stock(): void
    {
        $order = $this->order();
        $request = $this->cancellation($order);

        $this->assertSame(2, $this->stock->fresh()->reserved);

        $this->act('store-admin', "cancellations/{$request->id}/approve", [], 422);
        $this->act('customer-service', "cancellations/{$request->id}/validate");
        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->assertSame(Request::APPROVED, $request->fresh()->status);
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->payment_status);
        $this->assertSame(0, $this->stock->fresh()->reserved);
        $this->assertSame(10, $this->stock->fresh()->quantity);
    }

    public function test_paid_cancellation_needs_two_approvals_and_returns_stock(): void
    {
        $order = $this->paidOrder();
        $request = $this->cancellation($order);

        $this->act('customer-service', "cancellations/{$request->id}/validate");
        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->assertSame(Request::STORE_APPROVED, $request->fresh()->status);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(8, $this->stock->fresh()->quantity);

        $this->act('owner', "cancellations/{$request->id}/final-approve");

        $order->refresh();
        $this->assertSame(Order::CANCELLED, $order->status);
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertSame(10, $this->stock->fresh()->quantity);
        $this->assertSame('return', $this->stock->movements()->latest('id')->value('type'));

        $this->act('store-admin', "cancellations/{$request->id}/refunded");

        $this->assertSame(Order::REFUNDED, $order->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(Request::REFUNDED, $request->fresh()->status);
    }

    public function test_two_approvals_must_be_from_different_people(): void
    {
        $order = $this->paidOrder();
        $request = $this->cancellation($order);
        $superAdmin = $this->staff('super-admin');
        $this->act('customer-service', "cancellations/{$request->id}/validate");

        $this->actingAs($superAdmin)->postJson("/api/v1/admin/cancellations/{$request->id}/approve")->assertOk();
        $this->actingAs($superAdmin)->postJson("/api/v1/admin/cancellations/{$request->id}/final-approve")->assertUnprocessable();

        $this->assertSame(Request::STORE_APPROVED, $request->fresh()->status);
        $this->act('owner', "cancellations/{$request->id}/final-approve");
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    /** @return array<string, array{string, string}> */
    public static function approvalForbiddenProvider(): array
    {
        return [
            'admin toko validasi' => ['store-admin', 'validate'],
            'cs acc pertama' => ['customer-service', 'approve'],
            'pemilik acc pertama' => ['owner', 'approve'],
            'cs acc kedua' => ['customer-service', 'final-approve'],
            'admin toko acc kedua' => ['store-admin', 'final-approve'],
            'gudang tolak' => ['warehouse', 'reject'],
            'marketing validasi' => ['marketing', 'validate'],
        ];
    }

    #[DataProvider('approvalForbiddenProvider')]
    public function test_approval_permissions(string $role, string $action): void
    {
        $request = $this->cancellation($this->paidOrder());

        $this->act($role, "cancellations/{$request->id}/{$action}", ['reason' => 'x'], 403);
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $request = $this->cancellation($this->paidOrder());

        $this->act('owner', "cancellations/{$request->id}/final-approve", [], 422);
        $this->act('store-admin', "cancellations/{$request->id}/refunded", [], 422);
    }

    public function test_rejection_keeps_order_and_allows_new_request(): void
    {
        $order = $this->paidOrder();
        $request = $this->cancellation($order);

        $this->act('customer-service', "cancellations/{$request->id}/reject", [], 422);
        $this->act('customer-service', "cancellations/{$request->id}/reject", ['reason' => 'Sudah dikemas']);

        $this->assertSame(Request::REJECTED, $request->fresh()->status);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(8, $this->stock->fresh()->quantity);

        $this->act('customer-service', "cancellations/{$request->id}/validate", [], 422);
        $this->cancellation($order);
    }

    public function test_open_request_blocks_processing_and_shipping(): void
    {
        $order = $this->paidOrder();
        $request = $this->cancellation($order);

        $this->act('warehouse', "orders/{$order->id}/process", [], 422);

        $this->act('customer-service', "cancellations/{$request->id}/reject", ['reason' => 'Batal ajukan']);
        $this->act('warehouse', "orders/{$order->id}/process");
    }

    public function test_approval_fails_if_order_already_shipped(): void
    {
        $order = $this->paidOrder();
        $request = $this->cancellation($order);
        $this->act('customer-service', "cancellations/{$request->id}/validate");
        $order->forceFill(['status' => Order::SHIPPED])->save();
        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->act('owner', "cancellations/{$request->id}/final-approve", [], 422);

        $this->assertSame(Order::SHIPPED, $order->fresh()->status);
        $this->assertSame(8, $this->stock->fresh()->quantity);
    }

    public function test_overdue_flag_only_marks_and_changes_nothing(): void
    {
        $order = $this->paidOrder();
        $request = $this->cancellation($order);

        $this->travel(8)->days();

        $this->actingAs($this->staff('customer-service'))->getJson('/api/v1/admin/cancellations')->assertJsonPath('data.items.0.is_overdue', true);

        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame(Request::SUBMITTED, $request->fresh()->status);
        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_order_comments_follow_read_access(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->staff('warehouse'))->postJson("/api/v1/admin/orders/{$order->id}/comments", ['body' => 'Sudah dikemas'])->assertCreated();
        $this->actingAs($this->staff('owner'))->getJson("/api/v1/admin/orders/{$order->id}/comments")->assertJsonCount(1, 'data.comments');
        $this->actingAs($this->staff('marketing'))->getJson("/api/v1/admin/orders/{$order->id}/comments")->assertForbidden();
    }

    public function test_detail_contains_history_and_hides_secrets(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->staff('customer-service'))->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.customer_email', 'budi@example.com')
            ->assertJsonPath('data.items.0.line_total', 100000)
            ->assertJsonPath('data.histories.0.to_status', Order::PENDING_PAYMENT)
            ->assertJsonMissingPath('data.access_token_hash')
            ->assertJsonMissingPath('data.idempotency_key');
    }

    public function test_writes_require_enrolled_mfa(): void
    {
        $order = $this->paidOrder();
        $user = User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $user->assignRole('warehouse');

        $this->actingAs($user)->postJson("/api/v1/admin/orders/{$order->id}/process")->assertForbidden();
    }
}
