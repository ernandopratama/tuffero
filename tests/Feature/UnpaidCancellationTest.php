<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\CancellationService;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pelanggan membatalkan sendiri pesanan yang belum dibayar, tanpa persetujuan (D-123, D-138 tahap 2).
 */
class UnpaidCancellationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config(['services.midtrans.server_key' => 'SB-server', 'services.midtrans.client_key' => 'SB-client', 'services.midtrans.is_production' => false]);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        $this->stock = InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 20]);
    }

    private function order(string $phone = '081234567890', bool $withSnapToken = false): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => $phone,
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = Order::where('public_ref', $ref)->firstOrFail();

        if ($withSnapToken) {
            $order->forceFill(['snap_token' => 'snap-token-1'])->save();
        }

        return $order->fresh();
    }

    private function cancel(Order $order, int $status = 200): TestResponse
    {
        return $this->withSession(["order_access.{$order->public_ref}" => true])->postJson("/api/v1/orders/{$order->public_ref}/cancel")->assertStatus($status);
    }

    private function show(Order $order): array
    {
        return $this->withSession(["order_access.{$order->public_ref}" => true])->getJson("/api/v1/orders/{$order->public_ref}")->assertOk()->json('data');
    }

    private function fakeMidtrans(array $cancel, ?array $status = null): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/cancel' => Http::response($cancel),
            'api.sandbox.midtrans.com/v2/*/status' => Http::response($status ?? ['status_code' => '404']),
        ]);
    }

    public function test_unpaid_order_is_cancelled_instantly_and_stock_is_released(): void
    {
        Http::fake();
        $order = $this->order();
        $this->assertSame(2, $this->stock->fresh()->reserved);

        $this->cancel($order)->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame(Order::CANCELLED, $order->status);
        $this->assertSame('cancelled', $order->payment_status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(0, $this->stock->fresh()->reserved);
        $this->assertSame(20, $this->stock->fresh()->quantity);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status_type' => 'order', 'to_status' => 'cancelled', 'source' => 'customer']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.customer_cancelled']);
        Http::assertNothingSent();
    }

    public function test_no_midtrans_call_when_the_customer_never_opened_payment(): void
    {
        Http::fake();

        $this->cancel($this->order(withSnapToken: false));

        Http::assertNothingSent();
    }

    public function test_open_midtrans_transaction_is_cancelled_first(): void
    {
        $this->fakeMidtrans(['status_code' => '200', 'transaction_status' => 'cancel']);
        $order = $this->order(withSnapToken: true);

        $this->cancel($order);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/v2/{$order->order_number}/cancel") && $request->hasHeader('Authorization'));
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    public function test_transaction_that_does_not_exist_at_midtrans_is_fine(): void
    {
        $this->fakeMidtrans(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);
        $order = $this->order(withSnapToken: true);

        $this->cancel($order);

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    public function test_already_paid_at_midtrans_refuses_the_cancellation_and_changes_nothing(): void
    {
        $this->fakeMidtrans(['status_code' => '412', 'status_message' => 'Transaction status cannot be updated.'], ['status_code' => '200', 'transaction_status' => 'settlement']);
        $order = $this->order(withSnapToken: true);

        $this->cancel($order, 422)->assertJsonPath('errors.order.0', 'already_paid');

        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, $this->stock->fresh()->reserved);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'order.customer_cancelled']);
    }

    public function test_transaction_already_closed_at_midtrans_lets_the_cancellation_continue(): void
    {
        $this->fakeMidtrans(['status_code' => '412', 'status_message' => 'Transaction status cannot be updated.'], ['status_code' => '200', 'transaction_status' => 'expire']);
        $order = $this->order(withSnapToken: true);

        $this->cancel($order);

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    public function test_unreachable_midtrans_blocks_the_cancellation_and_retry_works(): void
    {
        Http::fake(['api.sandbox.midtrans.com/v2/*/cancel' => Http::sequence()->pushFailedConnection('timeout')->push(['status_code' => '200', 'transaction_status' => 'cancel'])]);
        $order = $this->order(withSnapToken: true);

        $this->cancel($order, 422)->assertJsonPath('errors.order.0', 'gateway_unreachable');
        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, $this->stock->fresh()->reserved);

        $this->cancel($order);
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    public function test_unexpected_midtrans_answer_blocks_the_cancellation(): void
    {
        $this->fakeMidtrans(['status_code' => '500', 'status_message' => 'Internal error']);
        $order = $this->order(withSnapToken: true);

        $this->cancel($order, 422)->assertJsonPath('errors.order.0', 'gateway_unreachable');

        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_paid_order_cannot_use_instant_cancellation(): void
    {
        $order = $this->order();
        app(OrderStatusService::class)->markPaid($order);

        $this->cancel($order, 422)->assertJsonPath('errors.order.0', 'already_paid');

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $flags = $this->show($order);
        $this->assertFalse($flags['can_cancel_now']);
        $this->assertTrue($flags['can_cancel'], 'Pesanan dibayar tetap lewat pengajuan ke CS.');
    }

    public function test_double_click_cancels_once(): void
    {
        $order = $this->order();

        $this->cancel($order);
        $this->cancel($order, 422)->assertJsonPath('errors.order.0', 'not_cancellable');

        $this->assertSame(1, $order->histories()->where('to_status', 'cancelled')->where('status_type', 'order')->count());
        $this->assertSame(20, $this->stock->fresh()->quantity);
        $this->assertSame(0, $this->stock->fresh()->reserved);
    }

    public function test_order_awaiting_review_can_be_cancelled_and_releases_stock(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => Order::AWAITING_APPROVAL])->save();

        $this->cancel($order);

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $this->stock->fresh()->reserved);
    }

    public function test_access_is_required_and_other_orders_are_not_reachable(): void
    {
        $order = $this->order();
        $this->flushSession();

        $this->postJson("/api/v1/orders/{$order->public_ref}/cancel")->assertNotFound();
        $this->postJson('/api/v1/orders/'.str()->uuid().'/cancel')->assertNotFound();

        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_open_request_is_closed_when_the_customer_cancels_directly(): void
    {
        $order = $this->order();
        $request = app(CancellationService::class)->submit($order, ['reason' => 'Salah pesan', 'channel' => 'whatsapp']);

        $this->cancel($order);

        $request->refresh();
        $this->assertSame(OrderCancellationRequest::REJECTED, $request->status);
        $this->assertStringContainsString('dibatalkan sendiri', $request->rejection_reason);
    }

    public function test_limit_per_phone_sends_further_cancellations_to_the_cs_request_flow(): void
    {
        Http::fake();
        $first = $this->order();
        $second = $this->order();
        $third = $this->order();

        $this->assertTrue($this->show($third)['can_cancel_now']);
        $this->cancel($first);
        $this->cancel($second);

        $flags = $this->show($third);
        $this->assertFalse($flags['can_cancel_now']);
        $this->assertTrue($flags['can_cancel'], 'Setelah batas, pelanggan tetap bisa mengajukan lewat CS.');
        $this->cancel($third, 422)->assertJsonPath('errors.order.0', 'auto_unavailable');
        $this->assertSame(Order::PENDING_PAYMENT, $third->fresh()->status);

        // Nomor lain tidak terpengaruh.
        $this->assertTrue($this->show($this->order('081299990000'))['can_cancel_now']);
    }

    public function test_limit_is_a_setting_and_old_cancellations_leave_the_window(): void
    {
        Http::fake();
        Setting::where('key', 'cancellation.auto_max_per_phone')->update(['value' => '1']);
        $first = $this->order();
        $second = $this->order();

        $this->cancel($first);
        $this->assertFalse($this->show($second)['can_cancel_now']);

        $first->histories()->update(['created_at' => now()->subDays(31)]);
        $this->assertTrue($this->show($second)['can_cancel_now'], 'Pembatalan lebih dari 30 hari lalu tidak dihitung.');
    }

    public function test_switch_off_returns_everything_to_the_request_flow(): void
    {
        Setting::where('key', 'cancellation.auto_unpaid_enabled')->update(['value' => '0']);
        $order = $this->order();

        $flags = $this->show($order);
        $this->assertFalse($flags['can_cancel_now']);
        $this->assertTrue($flags['can_cancel']);
        $this->cancel($order, 422)->assertJsonPath('errors.order.0', 'auto_unavailable');
        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_instant_cancel_is_offered_only_for_unpaid_orders(): void
    {
        $order = $this->order();

        $flags = $this->show($order);
        $this->assertTrue($flags['can_cancel_now']);
        $this->assertFalse($flags['can_cancel'], 'Satu tombol saja: batal langsung, bukan formulir pengajuan.');

        $this->cancel($order);
        $flags = $this->show($order);
        $this->assertFalse($flags['can_cancel_now']);
        $this->assertFalse($flags['can_cancel']);
    }
}
