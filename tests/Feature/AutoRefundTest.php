<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderCancellationRequest as Request;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use App\Services\RevenueService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Refund otomatis lewat Midtrans setelah ACC Admin Toko (D-138 tahap 4): bawaan mati, hanya kartu dan QRIS, VA tetap manual.
 */
class AutoRefundTest extends TestCase
{
    use RefreshDatabase;

    private const REFUND_URL = 'api.sandbox.midtrans.com/v2/*/refund';

    private const CANCEL_URL = 'api.sandbox.midtrans.com/v2/*/cancel';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config(['services.midtrans.server_key' => 'SB-server', 'services.midtrans.client_key' => 'SB-client', 'services.midtrans.is_production' => false]);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 20]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function enable(): void
    {
        Setting::updateOrCreate(['key' => 'cancellation.auto_refund_enabled'], ['value' => '1', 'type' => 'int', 'group' => 'cancellation', 'is_public' => false]);
    }

    /** Pesanan terbayar dengan event bayar (metode dan status bisa dipilih) dan pengajuan yang sudah divalidasi CS. */
    private function validatedRequest(string $paymentType = 'qris', string $transactionStatus = 'settlement'): Request
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();
        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = Order::where('public_ref', $ref)->firstOrFail();
        app(OrderStatusService::class)->markPaid($order);
        PaymentEvent::create(['order_id' => $order->id, 'transaction_id' => 'trx-'.$order->id, 'transaction_status' => $transactionStatus, 'payment_type' => $paymentType, 'gross_amount' => $order->total, 'payload' => []]);

        $this->withSession(["order_access.{$ref}" => true])->postJson("/api/v1/orders/{$ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'whatsapp'])->assertCreated();
        $request = Request::latest('id')->firstOrFail();
        $this->act('customer-service', "cancellations/{$request->id}/validate");

        return $request->fresh();
    }

    private function act(string $role, string $path, int $status = 200): TestResponse
    {
        return $this->actingAs($this->staff($role))->postJson("/api/v1/admin/{$path}")->assertStatus($status);
    }

    private function fakeRefund(array $body = ['status_code' => '200', 'status_message' => 'Success, refund request is approved', 'transaction_status' => 'refund']): void
    {
        Http::fake([self::REFUND_URL => Http::response($body), self::CANCEL_URL => Http::response(['status_code' => '200', 'transaction_status' => 'cancel'])]);
    }

    public function test_default_is_off_and_cancellation_waits_for_a_manual_refund(): void
    {
        Http::fake();
        $request = $this->validatedRequest();

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        Http::assertNothingSent();
        $order = Order::findOrFail($request->order_id);
        $this->assertSame(Order::CANCELLED, $order->status);
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertNull($request->fresh()->auto_refund_status);
        $row = $this->actingAs($this->staff('store-admin'))->getJson('/api/v1/admin/cancellations')->json('data.items.0');
        $this->assertFalse($row['auto_refund_available']);
    }

    public function test_qris_settlement_is_refunded_through_the_refund_api_with_a_fixed_key(): void
    {
        $this->enable();
        $this->fakeRefund();
        $request = $this->validatedRequest('qris', 'settlement');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $order = Order::findOrFail($request->order_id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => str_ends_with($sent->url(), "/v2/{$order->order_number}/refund")
            && $sent['refund_key'] === $request->request_number
            && $sent['amount'] === (int) $order->total
            && str_contains($sent['reason'], $request->request_number));
        $request->refresh();
        $this->assertSame(Request::REFUNDED, $request->status);
        $this->assertSame('done', $request->auto_refund_status);
        $this->assertNotNull($request->refunded_at);
        $this->assertNull($request->refunded_by_user_id, 'Dikembalikan sistem, bukan staf.');
        $this->assertSame(Order::REFUNDED, $order->status);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'to_status' => 'refunded', 'source' => 'midtrans_refund']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.auto_done']);
        $this->assertSame((int) $order->total, app(RevenueService::class)->refunded([now()->subDay(), now()->addDay()]), 'Laporan keuangan membaca refunded_at.');
    }

    public function test_card_in_capture_state_is_voided_not_refunded(): void
    {
        $this->enable();
        $this->fakeRefund();
        $request = $this->validatedRequest('credit_card', 'capture');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => str_ends_with($sent->url(), '/cancel'));
        $this->assertSame('done', $request->fresh()->auto_refund_status);
        $this->assertSame('refunded', Order::findOrFail($request->order_id)->payment_status);
    }

    public function test_card_that_settled_meanwhile_falls_back_to_refund(): void
    {
        $this->enable();
        Http::fake([
            self::CANCEL_URL => Http::response(['status_code' => '412', 'status_message' => 'Transaction status cannot be updated.']),
            self::REFUND_URL => Http::response(['status_code' => '200', 'transaction_status' => 'refund']),
        ]);
        $request = $this->validatedRequest('credit_card', 'capture');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        Http::assertSentCount(2);
        $this->assertSame('done', $request->fresh()->auto_refund_status);
    }

    public function test_settled_card_uses_the_refund_api_directly(): void
    {
        $this->enable();
        $this->fakeRefund();
        $request = $this->validatedRequest('credit_card', 'settlement');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => str_ends_with($sent->url(), '/refund'));
    }

    public function test_virtual_account_stays_manual_even_when_the_feature_is_on(): void
    {
        $this->enable();
        Http::fake();
        $request = $this->validatedRequest('bank_transfer', 'settlement');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        Http::assertNothingSent();
        $this->assertSame('refund_pending', Order::findOrFail($request->order_id)->payment_status);
        $this->assertNull($request->fresh()->auto_refund_status);
        $this->act('store-admin', "cancellations/{$request->id}/auto-refund", 422);
        $this->act('store-admin', "cancellations/{$request->id}/refunded");
        $this->assertSame(Request::REFUNDED, $request->fresh()->status);
    }

    public function test_midtrans_rejection_keeps_the_cancellation_and_the_money_waiting_and_alerts_staff(): void
    {
        $this->enable();
        $this->fakeRefund(['status_code' => '500', 'status_message' => 'Insufficient balance']);
        $otherStoreAdmin = $this->staff('store-admin');
        $request = $this->validatedRequest('qris');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $order = Order::findOrFail($request->order_id);
        $this->assertSame(Order::CANCELLED, $order->status, 'ACC tetap berhasil.');
        $this->assertSame('refund_pending', $order->payment_status);
        $request->refresh();
        $this->assertSame(Request::APPROVED, $request->status);
        $this->assertSame('failed', $request->auto_refund_status);
        $this->assertSame('Insufficient balance', $request->auto_refund_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.auto_failed']);
        $this->assertTrue($otherStoreAdmin->notifications->pluck('data.title')->contains(fn ($title) => str_contains($title, "Refund otomatis pesanan {$order->order_number} gagal")));
    }

    public function test_partial_refund_answer_is_not_treated_as_success(): void
    {
        $this->enable();
        $this->fakeRefund(['status_code' => '200', 'status_message' => 'ok', 'transaction_status' => 'partial_refund']);
        $request = $this->validatedRequest('qris');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->assertSame('failed', $request->fresh()->auto_refund_status);
        $this->assertSame('refund_pending', Order::findOrFail($request->order_id)->payment_status);
    }

    public function test_unreachable_midtrans_is_a_failure_and_never_breaks_the_approval(): void
    {
        $this->enable();
        Http::fake([self::REFUND_URL => Http::sequence()->pushFailedConnection('timeout')]);
        $request = $this->validatedRequest('qris');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->assertSame('failed', $request->fresh()->auto_refund_status);
        $this->assertSame(Order::CANCELLED, Order::findOrFail($request->order_id)->status);
    }

    public function test_explicit_retry_reuses_the_same_refund_key_and_cannot_run_twice(): void
    {
        $this->enable();
        Http::fake([self::REFUND_URL => Http::sequence()
            ->push(['status_code' => '500', 'status_message' => 'Insufficient balance'])
            ->push(['status_code' => '200', 'transaction_status' => 'refund'])]);
        $request = $this->validatedRequest('qris');

        $this->act('store-admin', "cancellations/{$request->id}/approve");
        $this->assertSame('failed', $request->fresh()->auto_refund_status);

        $this->act('store-admin', "cancellations/{$request->id}/auto-refund");
        $this->assertSame('done', $request->fresh()->auto_refund_status);
        $this->assertSame(Request::REFUNDED, $request->fresh()->status);

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]['refund_key'])->all();
        $this->assertSame([$request->request_number, $request->request_number], $keys);

        $this->act('store-admin', "cancellations/{$request->id}/auto-refund", 422);
        Http::assertSentCount(2);
    }

    public function test_failed_retry_reports_the_reason(): void
    {
        $this->enable();
        $this->fakeRefund(['status_code' => '500', 'status_message' => 'Insufficient balance']);
        $request = $this->validatedRequest('qris');
        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $response = $this->act('store-admin', "cancellations/{$request->id}/auto-refund", 422);

        $this->assertStringContainsString('Insufficient balance', $response->json('errors.request.0'));
        $this->assertSame('refund_pending', Order::findOrFail($request->order_id)->payment_status);
    }

    public function test_manual_refund_after_a_failure_blocks_any_further_automatic_attempt(): void
    {
        $this->enable();
        $this->fakeRefund(['status_code' => '500', 'status_message' => 'Insufficient balance']);
        $request = $this->validatedRequest('qris');
        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->act('store-admin', "cancellations/{$request->id}/refunded");
        $this->assertSame(Request::REFUNDED, $request->fresh()->status);

        $this->act('store-admin', "cancellations/{$request->id}/auto-refund", 422);
        Http::assertSentCount(1);
    }

    public function test_retry_button_is_for_store_admin_and_super_admin_only(): void
    {
        $this->enable();
        $this->fakeRefund(['status_code' => '500', 'status_message' => 'Insufficient balance']);
        $request = $this->validatedRequest('qris');
        $this->act('store-admin', "cancellations/{$request->id}/approve");

        $this->act('owner', "cancellations/{$request->id}/auto-refund", 403);
        $this->act('customer-service', "cancellations/{$request->id}/auto-refund", 403);
        $this->act('warehouse', "cancellations/{$request->id}/auto-refund", 403);
        Http::assertSentCount(1);

        $row = $this->actingAs($this->staff('store-admin'))->getJson('/api/v1/admin/cancellations')->json('data.items.0');
        $this->assertTrue($row['auto_refund_available']);
        $this->assertSame('failed', $row['auto_refund_status']);
    }

    public function test_feature_needs_midtrans_to_be_configured(): void
    {
        $this->enable();
        config(['services.midtrans.server_key' => '', 'services.midtrans.client_key' => '']);
        Http::fake();
        $request = $this->validatedRequest('qris');

        $this->act('store-admin', "cancellations/{$request->id}/approve");

        Http::assertNothingSent();
        $this->assertSame('refund_pending', Order::findOrFail($request->order_id)->payment_status);
    }
}
