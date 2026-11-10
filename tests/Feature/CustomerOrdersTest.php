<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $variant = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true])
            ->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500, 'is_active' => true]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->firstOrFail()->id, 'product_variant_id' => $variant->id, 'quantity' => 50]);
    }

    /** @param array<string, mixed> $extra */
    private function placeOrder(array $extra = []): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.Str::random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => '0812-3456-7890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
            ...$extra,
        ])->assertCreated()->json('data.public_ref');

        return Order::where('public_ref', $ref)->firstOrFail();
    }

    public function test_customer_checkout_links_order_to_session_user_only(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $order = $this->actingAs($user)->placeOrder(['user_id' => $victim->id]);

        $this->assertSame($user->id, $order->user_id);
    }

    public function test_guest_checkout_stays_unlinked_even_with_user_id_in_body(): void
    {
        $victim = User::factory()->create();

        $this->assertNull($this->placeOrder(['user_id' => $victim->id])->user_id);
    }

    public function test_staff_checkout_is_not_linked(): void
    {
        $staff = User::factory()->create()->assignRole('store-admin');

        $this->assertNull($this->actingAs($staff)->placeOrder()->user_id);
    }

    public function test_list_shows_only_own_orders(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->actingAs($user)->placeOrder();
        $theirs = $this->actingAs($other)->placeOrder();

        $response = $this->actingAs($user)->getJson('/api/v1/account/orders')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.public_ref', $mine->public_ref)
            ->assertJsonPath('data.items.0.item_count', 2)
            ->assertJsonPath('data.items.0.total', $mine->total);

        $this->assertStringNotContainsString($theirs->public_ref, $response->getContent());
        $this->assertArrayNotHasKey('customer_phone', $response->json('data.items.0'));
        $this->assertArrayNotHasKey('access_token_hash', $response->json('data.items.0'));
    }

    public function test_home_hero_greets_signed_in_customer_with_own_active_order_only(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['name' => 'Sinta <Uji> Rahayu']);
        $other = User::factory()->create();
        $order = $this->actingAs($user)->placeOrder();
        $theirs = $this->actingAs($other)->placeOrder();

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee('tf-hero-content is-welcome', false)
            ->assertSee('<em>Sinta</em>', false)
            ->assertSee('/pesanan/'.$order->public_ref, false)
            ->assertDontSee($theirs->public_ref);

        // Pesanan yang sudah selesai tidak lagi ditampilkan sebagai pesanan aktif.
        $order->update(['status' => Order::COMPLETED]);
        $this->actingAs($user)->get('/')->assertDontSee('tf-hero-order', false);
    }

    public function test_home_hero_stays_generic_for_guest_and_staff(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertDontSee('is-welcome', false);

        $staff = User::factory()->create()->assignRole('store-admin');
        $this->actingAs($staff)->get('/')->assertOk()->assertDontSee('is-welcome', false);
    }

    public function test_list_rejects_guest_and_staff(): void
    {
        $this->getJson('/api/v1/account/orders')->assertUnauthorized();

        $staff = User::factory()->create()->assignRole('store-admin');
        $this->actingAs($staff)->getJson('/api/v1/account/orders')->assertForbidden();
    }

    public function test_owner_can_open_order_without_session_grant_or_token(): void
    {
        $user = User::factory()->create();
        $order = $this->actingAs($user)->placeOrder();
        $this->flushSession();

        $this->actingAs($user)->getJson("/api/v1/orders/{$order->public_ref}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number);
    }

    public function test_order_shows_waybill_and_status_times_for_the_tracking_card(): void
    {
        $user = User::factory()->create();
        $order = $this->actingAs($user)->placeOrder();

        $this->actingAs($user)->getJson("/api/v1/orders/{$order->public_ref}")
            ->assertOk()
            ->assertJsonPath('data.shipment', null)
            ->assertJsonPath('data.timeline', []);

        $order->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'JT123', 'status' => 'shipped', 'shipping_fee' => 12000, 'shipped_at' => now()]);
        $order->histories()->create(['status_type' => 'order', 'to_status' => Order::SHIPPED, 'source' => 'admin']);
        $order->histories()->create(['status_type' => 'payment', 'to_status' => Order::PAID, 'source' => 'midtrans']);

        $this->actingAs($user)->getJson("/api/v1/orders/{$order->public_ref}")
            ->assertOk()
            ->assertJsonPath('data.shipment.waybill_id', 'JT123')
            ->assertJsonPath('data.shipment.status', 'shipped')
            ->assertJsonStructure(['data' => ['shipment' => ['updated_at']]])
            ->assertJsonCount(1, 'data.timeline')
            ->assertJsonStructure(['data' => ['timeline' => ['shipped']]]);
    }

    private function shippedOrder(User $user): Order
    {
        $order = $this->actingAs($user)->placeOrder();
        $order->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'JT123', 'status' => 'shipped', 'shipping_fee' => 12000, 'shipped_at' => now()]);

        return $order;
    }

    public function test_tracking_returns_courier_history_newest_first_and_caches_it(): void
    {
        config(['services.biteship.key' => 'test-key']);
        Http::fake(['api.biteship.com/*' => Http::response(['success' => true, 'history' => [
            ['note' => 'Paket diambil kurir', 'status' => 'picked', 'updated_at' => '2026-11-09T08:00:00+07:00'],
            ['note' => 'Paket tiba di hub <b>Surabaya</b>', 'status' => 'dropping_off', 'updated_at' => '2026-11-09T14:30:00+07:00'],
        ]])]);
        $user = User::factory()->create();
        $order = $this->shippedOrder($user);

        foreach ([1, 2] as $_) {
            $this->actingAs($user)->getJson("/api/v1/orders/{$order->public_ref}/tracking")
                ->assertOk()
                ->assertJsonPath('data.available', true)
                ->assertJsonPath('data.events.0.note', 'Paket tiba di hub Surabaya')
                ->assertJsonPath('data.events.1.status', 'picked');
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/trackings/JT123/couriers/jnt') && $r->hasHeader('Authorization', 'test-key'));
    }

    public function test_tracking_is_unavailable_without_waybill_or_key_and_never_calls_biteship(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $order = $this->actingAs($user)->placeOrder();

        config(['services.biteship.key' => 'test-key']);
        $this->actingAs($user)->getJson("/api/v1/orders/{$order->public_ref}/tracking")->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.events', []);

        $shipped = $this->shippedOrder($user);
        config(['services.biteship.key' => null]);
        $this->actingAs($user)->getJson("/api/v1/orders/{$shipped->public_ref}/tracking")->assertOk()->assertJsonPath('data.available', false);

        Http::assertNothingSent();
    }

    public function test_tracking_failure_is_not_cached_as_empty_history_and_is_not_retried_immediately(): void
    {
        config(['services.biteship.key' => 'test-key']);
        Http::fake(['api.biteship.com/*' => Http::response(['success' => false, 'error' => 'x'], 500)]);
        $user = User::factory()->create();
        $order = $this->shippedOrder($user);

        foreach ([1, 2] as $_) {
            $this->actingAs($user)->getJson("/api/v1/orders/{$order->public_ref}/tracking")->assertOk()->assertJsonPath('data.available', false);
        }

        Http::assertSentCount(1);
    }

    public function test_tracking_respects_the_daily_budget_and_order_access(): void
    {
        config(['services.biteship.key' => 'test-key', 'services.biteship.daily_tracking_limit' => 1]);
        Http::fake(['api.biteship.com/*' => Http::response(['success' => true, 'history' => [['note' => 'ok', 'status' => 'picked', 'updated_at' => '2026-11-09T08:00:00+07:00']]])]);
        $user = User::factory()->create();
        $first = $this->shippedOrder($user);
        $second = $this->actingAs($user)->placeOrder();
        $second->shipment()->create(['provider' => 'manual', 'courier_code' => 'sicepat', 'service_code' => 'reg', 'waybill_id' => 'SC999', 'status' => 'shipped', 'shipping_fee' => 11000, 'shipped_at' => now()]);

        $this->actingAs($user)->getJson("/api/v1/orders/{$first->public_ref}/tracking")->assertJsonPath('data.available', true);
        $this->actingAs($user)->getJson("/api/v1/orders/{$second->public_ref}/tracking")->assertJsonPath('data.available', false);

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/orders/{$first->public_ref}/tracking")->assertNotFound();
    }

    public function test_other_customer_and_guest_cannot_open_order(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->actingAs($user)->placeOrder();
        $this->flushSession();

        $this->actingAs($other)->getJson("/api/v1/orders/{$order->public_ref}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'x', 'channel' => 'email'])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson("/api/v1/orders/{$order->public_ref}")->assertNotFound();
    }

    public function test_guest_order_is_not_reachable_through_null_user_id(): void
    {
        $order = $this->placeOrder();
        $this->flushSession();

        $this->getJson("/api/v1/orders/{$order->public_ref}")->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/orders/{$order->public_ref}")->assertNotFound();
    }
}
