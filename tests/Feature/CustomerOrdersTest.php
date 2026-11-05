<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
