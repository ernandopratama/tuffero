<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MidtransService;
use App\Services\OrderExpiryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-test';

    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config(['services.midtrans' => ['server_key' => self::SERVER_KEY, 'client_key' => 'SB-Mid-client-test', 'is_production' => false]]);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        $this->stock = InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function order(): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

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

    private function notification(Order $order, string $status = 'settlement', array $overrides = []): array
    {
        $payload = [
            'order_id' => $order->order_number,
            'status_code' => '200',
            'gross_amount' => $order->total.'.00',
            'transaction_id' => 'trx-'.$order->id,
            'transaction_status' => $status,
            'fraud_status' => 'accept',
            'payment_type' => 'qris',
            ...$overrides,
        ];
        $payload['signature_key'] ??= hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY);

        return $payload;
    }

    public function test_snap_token_uses_database_total_and_is_reused(): void
    {
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'snap-123', 'redirect_url' => 'https://x'], 201)]);
        $order = $this->order();

        foreach ([1, 2] as $_) {
            $this->withSession(["order_access.{$order->public_ref}" => true])
                ->postJson("/api/v1/orders/{$order->public_ref}/payment", ['gross_amount' => 1])
                ->assertOk()
                ->assertJsonPath('data.snap_token', 'snap-123');
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r['transaction_details'] === ['order_id' => $order->order_number, 'gross_amount' => $order->total]
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::SERVER_KEY.':')));
    }

    public function test_default_payment_methods_are_qris_virtual_account_and_card_only(): void
    {
        // Konstanta, bukan config: config membaca MIDTRANS_ENABLED_PAYMENTS dari .env mesin ini.
        $methods = MidtransService::DEFAULT_PAYMENT_METHODS;

        $this->assertContains('other_qris', $methods);
        $this->assertContains('bca_va', $methods);
        $this->assertContains('credit_card', $methods);
        $this->assertNotContains('indomaret', $methods);
        $this->assertNotContains('akulaku', $methods);
    }

    public function test_snap_request_limits_payment_methods_only_when_configured(): void
    {
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'snap-123'], 201)]);

        $first = $this->order();
        $this->withSession(["order_access.{$first->public_ref}" => true])->postJson("/api/v1/orders/{$first->public_ref}/payment")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => ! isset($r['enabled_payments']));

        config(['services.midtrans.enabled_payments' => ['qris', 'bca_va']]);
        $second = $this->order();
        $this->withSession(["order_access.{$second->public_ref}" => true])->postJson("/api/v1/orders/{$second->public_ref}/payment")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => ($r['enabled_payments'] ?? null) === ['qris', 'bca_va']);
    }

    public function test_payment_token_requires_order_access(): void
    {
        Http::fake();
        $order = $this->order();
        $this->flushSession();

        $this->postJson("/api/v1/orders/{$order->public_ref}/payment")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_payment_token_is_refused_when_not_configured_or_not_payable(): void
    {
        Http::fake();
        $order = $this->order();
        $session = ["order_access.{$order->public_ref}" => true];

        config(['services.midtrans.server_key' => null]);
        $this->withSession($session)->postJson("/api/v1/orders/{$order->public_ref}/payment")->assertStatus(503);

        config(['services.midtrans.server_key' => self::SERVER_KEY]);
        $order->forceFill(['status' => Order::EXPIRED])->save();
        $this->withSession($session)->postJson("/api/v1/orders/{$order->public_ref}/payment")->assertUnprocessable();

        Http::assertNothingSent();
    }

    private function sync(Order $order)
    {
        return $this->withSession(["order_access.{$order->public_ref}" => true])->postJson("/api/v1/orders/{$order->public_ref}/payment/sync");
    }

    public function test_sync_marks_order_paid_only_when_midtrans_confirms_it(): void
    {
        $order = $this->order();
        $this->syncOrderFor($order, $this->notification($order));

        $this->sync($order)->assertOk()->assertJsonPath('data.status', Order::PAID);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), "/v2/{$order->order_number}/status") && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::SERVER_KEY.':')));
    }

    private function syncOrderFor(Order $order, array $statusResponse, int $httpStatus = 200): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response(['token' => 'snap-123'], 201),
            'api.sandbox.midtrans.com/*' => Http::response($statusResponse, $httpStatus),
        ]);
        $this->withSession(["order_access.{$order->public_ref}" => true])->postJson("/api/v1/orders/{$order->public_ref}/payment")->assertOk();
    }

    public function test_sync_does_not_pay_for_pending_forged_or_mismatched_status(): void
    {
        foreach ([
            fn (Order $o) => $this->notification($o, 'pending'),
            fn (Order $o) => $this->notification($o, 'settlement', ['signature_key' => 'forged']),
            fn (Order $o) => $this->notification($o, 'settlement', ['gross_amount' => '1.00']),
        ] as $build) {
            $order = $this->order();
            $this->syncOrderFor($order, $build($order));

            $this->sync($order)->assertOk()->assertJsonPath('data.status', Order::PENDING_PAYMENT);
            $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
        }
    }

    public function test_sync_is_a_noop_when_midtrans_has_no_transaction_and_is_rate_limited_per_order(): void
    {
        $order = $this->order();
        $this->syncOrderFor($order, ['status_code' => '404', 'status_message' => 'Transaction doesn\'t exist.'], 404);

        $this->sync($order)->assertOk()->assertJsonPath('data.status', Order::PENDING_PAYMENT);
        $this->sync($order)->assertOk();

        Http::assertSentCount(2); // 1 token Snap + 1 status; panggilan kedua ditahan cooldown 5 detik
    }

    public function test_sync_needs_order_access_and_an_opened_payment(): void
    {
        $order = $this->order();
        Http::fake();

        $this->flushSession();
        $this->postJson("/api/v1/orders/{$order->public_ref}/payment/sync")->assertNotFound();
        $this->sync($order)->assertOk()->assertJsonPath('data.status', Order::PENDING_PAYMENT);

        Http::assertNothingSent();
    }

    public function test_verified_settlement_marks_order_paid_once(): void
    {
        $order = $this->order();
        $payload = $this->notification($order);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(1, PaymentEvent::count());
        $this->assertSame(8, $this->stock->fresh()->quantity);
        $this->assertArrayNotHasKey('signature_key', PaymentEvent::first()->payload);
    }

    public function test_pending_and_challenge_notifications_do_not_mark_paid(): void
    {
        $order = $this->order();

        $this->postJson('/api/v1/webhooks/midtrans', $this->notification($order, 'pending', ['status_code' => '201']))->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $this->notification($order, 'capture', ['fraud_status' => 'challenge']))->assertOk();

        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, PaymentEvent::count());
    }

    public function test_forged_or_mismatched_notifications_are_rejected(): void
    {
        $order = $this->order();

        $this->postJson('/api/v1/webhooks/midtrans', $this->notification($order, 'settlement', ['signature_key' => str_repeat('a', 128)]))->assertForbidden();
        $this->postJson('/api/v1/webhooks/midtrans', $this->notification($order, 'settlement', ['gross_amount' => '1000.00']))->assertForbidden();
        $this->postJson('/api/v1/webhooks/midtrans', $this->notification($order, 'settlement', ['order_id' => 'TF-TIDAK-ADA']))->assertForbidden();
        $this->postJson('/api/v1/webhooks/midtrans', ['order_id' => $order->order_number])->assertForbidden();

        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_late_payment_for_expired_order_notifies_admin_without_reviving_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('store-admin');
        $order = $this->order();
        $order->forceFill(['expires_at' => now()->subMinute()])->save();
        app(OrderExpiryService::class)->expireDue();

        $this->postJson('/api/v1/webhooks/midtrans', $this->notification($order))->assertOk();

        $this->assertSame(Order::EXPIRED, $order->fresh()->status);
        $this->assertSame(10, $this->stock->fresh()->quantity);
        $this->assertTrue($admin->notifications->contains(fn ($n) => $n->data['event'] === 'payment_late' && $n->data['label'] === $order->order_number));
    }
}
