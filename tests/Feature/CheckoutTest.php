<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderExpiryService;
use App\Services\PhoneOtpService;
use App\Services\Shipping\ShippingProvider;
use App\Services\Shipping\UnavailableShippingProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'MLG')->firstOrFail();
    }

    private function variant(string $sku = 'NAS-01', int $stock = 10, array $attributes = [], array $product = []): ProductVariant
    {
        $model = Product::create(['name' => "Produk {$sku}", 'slug' => strtolower($sku), 'is_published' => true, ...$product]);
        $variant = $model->variants()->create(['sku' => $sku, 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500, ...$attributes]);

        if ($stock > 0) {
            InventoryStock::create(['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => $stock]);
        }

        return $variant;
    }

    private function body(array $overrides = []): array
    {
        return [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'Budi@Example.com',
            'customer_phone' => '0812-3456-7890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
            ...$overrides,
        ];
    }

    private function checkout(array $overrides = [], string $key = 'key-0000000000000001')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', $this->body($overrides));
    }

    private function addToCart(string $sku = 'NAS-01', int $quantity = 2): void
    {
        $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => $quantity])->assertOk();
    }

    private function stock(ProductVariant $variant): InventoryStock
    {
        return InventoryStock::where('product_variant_id', $variant->id)->firstOrFail();
    }

    public function test_guest_checkout_requires_whatsapp_otp_when_enabled(): void
    {
        config(['services.whatsapp.otp_required' => true]);
        $this->variant();
        $this->addToCart();

        $this->checkout()->assertStatus(422)->assertJsonPath('errors.customer_phone.0', 'phone_unverified');
        $this->withCredentials()->withCookie(PhoneOtpService::DEVICE_COOKIE, app(PhoneOtpService::class)->deviceCookie('+6281111111111')->getValue());
        $this->checkout([], 'key-0000000000000002')->assertStatus(422)->assertJsonPath('errors.customer_phone.0', 'phone_unverified');
        $this->assertSame(0, Order::count());

        $this->withCookie(PhoneOtpService::DEVICE_COOKIE, app(PhoneOtpService::class)->deviceCookie('+6281234567890')->getValue());
        $this->checkout([], 'key-0000000000000003')->assertCreated();
    }

    public function test_guest_cannot_check_out_with_a_member_number_when_otp_is_enabled(): void
    {
        config(['services.whatsapp.otp_required' => true]);
        User::factory()->create(['phone' => '+6281234567890', 'phone_verified_at' => now()]);
        $this->variant();
        $this->addToCart();
        $this->withCredentials()->withCookie(PhoneOtpService::DEVICE_COOKIE, app(PhoneOtpService::class)->deviceCookie('+6281234567890')->getValue());

        $this->checkout()->assertStatus(422)->assertJsonPath('errors.customer_phone.0', 'phone_requires_login');
    }

    public function test_signed_in_customer_skips_guest_otp(): void
    {
        config(['services.whatsapp.otp_required' => true]);
        $this->actingAs(User::factory()->create());
        $this->variant();
        $this->addToCart();

        $this->checkout()->assertCreated();
    }

    public function test_cart_uses_server_price_and_ignores_client_price(): void
    {
        $this->variant();

        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2, 'price' => 1])
            ->assertOk()
            ->assertJsonPath('data.items.0.price', 50000)
            ->assertJsonPath('data.subtotal', 100000)
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.can_checkout', true);

        ProductVariant::where('sku', 'NAS-01')->update(['price' => 60000]);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.subtotal', 120000);
    }

    public function test_cart_rejects_draft_inactive_and_unknown_products(): void
    {
        $this->variant('DRAF', 5, [], ['is_published' => false]);
        $this->variant('NONAKTIF', 5, ['is_active' => false]);

        foreach (['DRAF', 'NONAKTIF', 'TIDAK-ADA'] as $sku) {
            $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 1])->assertUnprocessable();
        }
    }

    /** @return array<string, array{int}> */
    public static function invalidQuantityProvider(): array
    {
        return ['nol' => [0], 'minus' => [-1], 'di atas batas keras' => [1000]];
    }

    #[DataProvider('invalidQuantityProvider')]
    public function test_cart_quantity_limits(int $quantity): void
    {
        $this->variant();

        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => $quantity])->assertUnprocessable();
    }

    public function test_cart_total_quantity_is_capped_when_adding_repeatedly(): void
    {
        $this->variant('NAS-01', 200);
        $this->addToCart('NAS-01', 90);
        $this->addToCart('NAS-01', 90);

        $this->getJson('/api/v1/cart')->assertJsonPath('data.items.0.quantity', 50)->assertJsonPath('data.items.0.max_quantity', 50);
    }

    public function test_cart_follows_the_per_variant_order_limit_set_by_admin(): void
    {
        $variant = $this->variant('NAS-01', 200, ['max_order_quantity' => 5]);
        $this->addToCart('NAS-01', 4);
        $this->addToCart('NAS-01', 4);

        $this->getJson('/api/v1/cart')->assertJsonPath('data.items.0.quantity', 5)->assertJsonPath('data.subtotal', 5 * $variant->price);

        // Batas diturunkan setelah barang masuk keranjang: jumlah ikut turun, bukan lolos ke checkout.
        $variant->update(['max_order_quantity' => 3]);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.items.0.quantity', 3)->assertJsonPath('data.subtotal', 3 * $variant->price);
    }

    public function test_cart_marks_stock_and_availability_problems_and_can_be_edited(): void
    {
        $this->variant('NAS-01', 3);
        $this->addToCart('NAS-01', 5);

        $this->getJson('/api/v1/cart')
            ->assertJsonPath('data.items.0.issue', 'insufficient_stock')
            ->assertJsonPath('data.items.0.available', 3)
            ->assertJsonPath('data.can_checkout', false)
            ->assertJsonMissingPath('data.items.0.variant_id');

        $this->patchJson('/api/v1/cart/items/NAS-01', ['quantity' => 2])
            ->assertJsonPath('data.items.0.issue', null)
            ->assertJsonPath('data.items.0.available', null)
            ->assertJsonPath('data.can_checkout', true);

        Product::query()->update(['is_published' => false]);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.items.0.issue', 'unavailable');

        $this->deleteJson('/api/v1/cart/items/NAS-01')->assertJsonCount(0, 'data.items');
    }

    public function test_checkout_creates_order_reserves_stock_and_clears_cart(): void
    {
        $variant = $this->variant();
        $this->addToCart();

        $response = $this->checkout(['price' => 1, 'total' => 1, 'shipping_fee' => 0, 'status' => 'paid'])->assertCreated();

        $order = Order::with('items')->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        $this->assertSame(100000, $order->subtotal);
        $this->assertSame(12000, $order->shipping_fee);
        $this->assertSame(112000, $order->total);
        $this->assertSame(Order::PENDING_PAYMENT, $order->status);
        $this->assertSame($this->warehouse->id, $order->warehouse_id);
        $this->assertSame('budi@example.com', $order->customer_email);
        $this->assertSame('+6281234567890', $order->customer_phone);
        $this->assertSame('Produk NAS-01', $order->items[0]->product_name_snapshot);
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $order->expires_at->getTimestamp(), 5);
        $this->assertSame(hash('sha256', $response->json('data.token')), $order->access_token_hash);

        $stock = $this->stock($variant);
        $this->assertSame(10, $stock->quantity);
        $this->assertSame(2, $stock->reserved);
        $this->assertSame(1, $order->reservations()->where('status', 'active')->count());
        $this->assertSame('reserve', $stock->movements()->value('type'));
        $this->assertSame(1, $order->histories()->count());

        $this->getJson('/api/v1/cart')->assertJsonCount(0, 'data.items');
    }

    public function test_cart_selection_drives_totals_and_blocks_checkout_when_nothing_is_selected(): void
    {
        $this->variant('NAS-01');
        $this->variant('MAC-01');
        $this->addToCart('NAS-01', 2);
        $this->addToCart('MAC-01', 1);

        $this->getJson('/api/v1/cart')->assertJsonPath('data.subtotal', 150000)->assertJsonPath('data.selected_count', 2)->assertJsonPath('data.can_checkout', true);

        $this->putJson('/api/v1/cart/selection', ['skus' => ['MAC-01']])
            ->assertOk()
            ->assertJsonPath('data.subtotal', 50000)
            ->assertJsonPath('data.selected_count', 1)
            ->assertJsonPath('data.items.0.selected', false)
            ->assertJsonPath('data.items.1.selected', true);

        $this->putJson('/api/v1/cart/selection', ['skus' => []])
            ->assertOk()
            ->assertJsonPath('data.subtotal', 0)
            ->assertJsonPath('data.can_checkout', false);
        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('cart');

        // Menambah produk lagi mencentangnya kembali.
        $this->addToCart('NAS-01', 1);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.items.0.selected', true)->assertJsonPath('data.selected_count', 1);
    }

    public function test_checkout_buys_only_selected_items_and_keeps_the_rest_in_the_cart(): void
    {
        $nastar = $this->variant('NAS-01');
        $macaron = $this->variant('MAC-01');
        $this->addToCart('NAS-01', 2);
        $this->addToCart('MAC-01', 1);
        $this->putJson('/api/v1/cart/selection', ['skus' => ['NAS-01']])->assertOk();

        $response = $this->checkout()->assertCreated();
        $order = Order::with('items')->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        $this->assertSame(100000, $order->subtotal);
        $this->assertSame(['NAS-01'], $order->items->pluck('sku_snapshot')->all());
        $this->assertSame(2, $this->stock($nastar)->reserved);
        $this->assertSame(0, $this->stock($macaron)->reserved);

        $this->getJson('/api/v1/cart')->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.sku', 'MAC-01');
    }

    public function test_cart_selection_validates_input_and_ignores_unavailable_items(): void
    {
        $this->variant('NAS-01');
        $this->addToCart('NAS-01', 1);

        $this->putJson('/api/v1/cart/selection', [])->assertUnprocessable()->assertJsonValidationErrors('skus');
        $this->putJson('/api/v1/cart/selection', ['skus' => [['x']]])->assertUnprocessable();

        ProductVariant::where('sku', 'NAS-01')->update(['is_active' => false]);
        $this->putJson('/api/v1/cart/selection', ['skus' => ['NAS-01']])
            ->assertOk()
            ->assertJsonPath('data.items.0.selected', false)
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_snapshot_does_not_change_when_price_changes_later(): void
    {
        $variant = $this->variant();
        $this->addToCart();
        $ref = $this->checkout()->json('data.public_ref');

        $variant->update(['price' => 1]);

        $this->assertSame(50000, Order::where('public_ref', $ref)->firstOrFail()->items()->value('unit_price'));
    }

    public function test_reservation_duration_follows_setting(): void
    {
        Setting::where('key', 'checkout.reservation_minutes')->update(['value' => '10']);
        $this->variant();
        $this->addToCart();

        $ref = $this->checkout()->json('data.public_ref');

        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), Order::where('public_ref', $ref)->firstOrFail()->expires_at->getTimestamp(), 5);
    }

    public function test_same_idempotency_key_does_not_create_second_order_or_reserve_twice(): void
    {
        $variant = $this->variant();
        $this->addToCart();

        $first = $this->checkout()->assertCreated();
        $second = $this->checkout()->assertOk();

        $this->assertSame($first->json('data.public_ref'), $second->json('data.public_ref'));
        $this->assertNull($second->json('data.token'));
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $this->stock($variant)->reserved);
    }

    public function test_replay_from_other_session_gets_nothing(): void
    {
        $this->variant();
        $this->addToCart();
        $this->checkout()->assertCreated();

        $this->flushSession();

        $this->checkout()->assertStatus(409)->assertJsonMissingPath('data.public_ref');
    }

    public function test_one_phone_cannot_hold_stock_with_too_many_unpaid_orders(): void
    {
        $this->variant('NAS-01', 100);

        foreach (['key-0000000000000001', 'key-0000000000000002', 'key-0000000000000003'] as $key) {
            $this->addToCart();
            $this->checkout([], $key)->assertCreated();
        }

        $this->addToCart();
        $this->checkout([], 'key-0000000000000004')->assertUnprocessable()->assertJsonPath('errors.customer_phone.0', 'too_many_unpaid_orders');
        $this->assertSame(3, Order::count());
        $this->assertSame(6, $this->stock(ProductVariant::firstOrFail())->reserved);

        // Nomor lain tidak terpengaruh, dan retry dengan kunci lama tetap mengembalikan pesanan yang sama.
        $this->checkout(['customer_phone' => '0812-9999-0000'], 'key-0000000000000005')->assertCreated();
        $this->checkout([], 'key-0000000000000001')->assertOk();
    }

    public function test_unpaid_limit_and_daily_limit_follow_settings(): void
    {
        $this->variant('NAS-01', 100);
        Setting::where('key', 'antifraud.max_unpaid_orders')->update(['value' => '1']);

        $this->addToCart();
        $this->checkout([], 'key-0000000000000001')->assertCreated();
        $this->addToCart();
        $this->checkout([], 'key-0000000000000002')->assertUnprocessable()->assertJsonPath('errors.customer_phone.0', 'too_many_unpaid_orders');

        Setting::where('key', 'antifraud.max_unpaid_orders')->update(['value' => '10']);
        Setting::where('key', 'antifraud.max_orders_per_day')->update(['value' => '1']);
        $this->checkout([], 'key-0000000000000003')->assertUnprocessable()->assertJsonPath('errors.customer_phone.0', 'daily_order_limit');

        // Pesanan lebih dari 24 jam tidak dihitung.
        Order::query()->update(['created_at' => now()->subDays(2)]);
        $this->checkout([], 'key-0000000000000004')->assertCreated();
    }

    public function test_checkout_requires_idempotency_key(): void
    {
        $this->variant();
        $this->addToCart();

        $this->postJson('/api/v1/checkout', $this->body())->assertStatus(400);
        $this->withHeader('Idempotency-Key', 'pendek')->postJson('/api/v1/checkout', $this->body())->assertStatus(400);
    }

    public function test_checkout_rejects_empty_cart_and_insufficient_stock(): void
    {
        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('cart');

        $this->variant('NAS-01', 1);
        $this->addToCart('NAS-01', 2);

        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_recheck_rejects_product_withdrawn_after_adding(): void
    {
        $this->variant();
        $this->addToCart();
        Product::query()->update(['is_published' => false]);

        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('cart');
    }

    public function test_order_is_never_split_across_warehouses(): void
    {
        $a = $this->variant('A', 5);
        $b = $this->variant('B', 0);
        $second = Warehouse::create(['code' => 'JKT', 'name' => 'Jakarta', 'city' => 'Jakarta', 'is_active' => true]);
        InventoryStock::create(['warehouse_id' => $second->id, 'product_variant_id' => $b->id, 'quantity' => 5]);

        $this->addToCart('A', 1);
        $this->addToCart('B', 1);

        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertSame(0, $this->stock($a)->reserved);

        InventoryStock::create(['warehouse_id' => $second->id, 'product_variant_id' => $a->id, 'quantity' => 5]);

        $ref = $this->checkout()->assertCreated()->json('data.public_ref');
        $this->assertSame($second->id, Order::where('public_ref', $ref)->firstOrFail()->warehouse_id);
    }

    public function test_inactive_warehouse_is_not_used(): void
    {
        $this->variant();
        $this->addToCart();
        $this->warehouse->update(['is_active' => false]);

        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('cart');
    }

    public function test_unknown_courier_is_rejected_and_fee_comes_from_server(): void
    {
        $this->variant();
        $this->addToCart();

        $this->checkout(['courier' => 'palsu', 'service' => 'gratis'])->assertUnprocessable()->assertJsonValidationErrors('courier');

        $this->postJson('/api/v1/shipping/rates', ['postal_code' => '65111'])
            ->assertOk()
            ->assertJsonPath('data.rates.0.courier', 'jnt')
            ->assertJsonPath('data.rates.1.courier', 'sicepat');
    }

    public function test_shipping_rates_require_cart_and_valid_postal_code(): void
    {
        $this->postJson('/api/v1/shipping/rates', ['postal_code' => '65111'])->assertUnprocessable();

        $this->variant();
        $this->addToCart();
        $this->postJson('/api/v1/shipping/rates', ['postal_code' => 'abc'])->assertUnprocessable();
    }

    public function test_shipping_rates_are_limited_to_ten_per_minute(): void
    {
        $this->variant();
        $this->addToCart();

        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/shipping/rates', ['postal_code' => '65111'])->assertOk();
        }

        $this->postJson('/api/v1/shipping/rates', ['postal_code' => '65111'])->assertTooManyRequests();
    }

    public function test_checkout_is_closed_outside_local_until_biteship_is_connected(): void
    {
        $this->variant();
        $this->addToCart();
        $this->app->bind(ShippingProvider::class, UnavailableShippingProvider::class);

        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('shipping');
        $this->assertSame(0, Order::count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidBodyProvider(): array
    {
        return [
            'nama kosong' => [['customer_name' => ''], 'customer_name'],
            'email salah' => [['customer_email' => 'bukan-email'], 'customer_email'],
            'hp salah' => [['customer_phone' => '12345'], 'customer_phone'],
            'kode pos salah' => [['address' => ['street' => 'x', 'district' => 'x', 'city' => 'x', 'province' => 'x', 'postal_code' => '1']], 'address.postal_code'],
            'alamat kosong' => [['address' => []], 'address'],
        ];
    }

    public function test_checkout_stores_map_pin_rounded(): void
    {
        $this->variant();
        $this->addToCart();

        $ref = $this->checkout(['address' => [...$this->body()['address'], 'latitude' => -7.871234567891, 'longitude' => '112.52345678912']])->assertCreated()->json('data.public_ref');
        $saved = Order::where('public_ref', $ref)->firstOrFail()->shipping_address;
        $this->assertSame([-7.8712346, 112.5234568], [$saved['latitude'], $saved['longitude']]);
    }

    public function test_checkout_without_map_pin_stores_no_coordinates(): void
    {
        $this->variant();
        $this->addToCart();

        $ref = $this->checkout(['address' => [...$this->body()['address'], 'latitude' => null, 'longitude' => null]])->assertCreated()->json('data.public_ref');
        $this->assertArrayNotHasKey('latitude', Order::where('public_ref', $ref)->firstOrFail()->shipping_address);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPinProvider(): array
    {
        return [
            'di luar Indonesia' => [['latitude' => 51.5, 'longitude' => -0.12]],
            'hanya satu sisi' => [['latitude' => -7.9]],
            'bukan angka' => [['latitude' => 'x', 'longitude' => 'y']],
        ];
    }

    #[DataProvider('invalidPinProvider')]
    public function test_checkout_rejects_invalid_map_pin(array $pin): void
    {
        $this->variant();
        $this->addToCart();

        $this->checkout(['address' => [...$this->body()['address'], ...$pin]])->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    #[DataProvider('invalidBodyProvider')]
    public function test_checkout_validation(array $overrides, string $field): void
    {
        $this->variant();
        $this->addToCart();

        $this->checkout($overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_order_is_only_visible_with_token_or_session_grant(): void
    {
        $this->variant();
        $this->addToCart();
        $response = $this->checkout()->assertCreated();
        $ref = $response->json('data.public_ref');
        $token = $response->json('data.token');

        $this->getJson("/api/v1/orders/{$ref}")->assertOk()->assertJsonMissingPath('data.access_token_hash')->assertJsonMissingPath('data.id');

        $this->flushSession();

        $this->getJson("/api/v1/orders/{$ref}")->assertNotFound();
        $this->withHeader('X-Order-Token', 'salah')->getJson("/api/v1/orders/{$ref}")->assertNotFound();
        $this->withHeader('X-Order-Token', $token)->getJson("/api/v1/orders/{$ref}")->assertOk()->assertJsonPath('data.total', 112000);
    }

    public function test_order_items_show_product_photo_and_keep_snapshot_when_variant_is_deleted(): void
    {
        $variant = $this->variant();
        $variant->product->images()->create(['path' => 'products/1/foto.webp', 'alt' => 'Foto', 'sort_order' => 0]);
        $this->addToCart();
        $ref = $this->checkout()->assertCreated()->json('data.public_ref');

        $this->getJson("/api/v1/orders/{$ref}")->assertOk()->assertJsonPath('data.items.0.photo', '/storage/products/1/foto.webp');

        Order::where('public_ref', $ref)->firstOrFail()->items()->update(['product_variant_id' => null]);

        $this->getJson("/api/v1/orders/{$ref}")->assertOk()
            ->assertJsonPath('data.items.0.photo', null)
            ->assertJsonPath('data.items.0.product_name', 'Produk NAS-01');
    }

    public function test_lookup_needs_matching_contact_and_gives_same_answer_on_failure(): void
    {
        $this->variant();
        $this->addToCart();
        $ref = $this->checkout()->json('data.public_ref');
        $number = Order::where('public_ref', $ref)->value('order_number');
        $this->flushSession();

        $wrong = $this->postJson('/api/v1/orders/lookup', ['order_number' => $number, 'contact' => 'lain@example.com'])->assertNotFound();
        $missing = $this->postJson('/api/v1/orders/lookup', ['order_number' => 'TF0000-000000', 'contact' => 'budi@example.com'])->assertNotFound();
        $this->assertSame($wrong->json(), $missing->json());
        $this->getJson("/api/v1/orders/{$ref}")->assertNotFound();

        $this->postJson('/api/v1/orders/lookup', ['order_number' => strtolower($number), 'contact' => 'BUDI@example.com'])->assertOk()->assertJsonPath('data.public_ref', $ref);
        $this->getJson("/api/v1/orders/{$ref}")->assertOk();

        $this->flushSession();
        $this->postJson('/api/v1/orders/lookup', ['order_number' => $number, 'contact' => '+62 812 3456 7890'])->assertOk();
    }

    public function test_lookup_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/orders/lookup', ['order_number' => 'TF0000-000000', 'contact' => 'x@example.com'])->assertNotFound();
        }

        $this->postJson('/api/v1/orders/lookup', ['order_number' => 'TF0000-000000', 'contact' => 'x@example.com'])->assertTooManyRequests();
    }

    public function test_order_is_expired_and_stock_released_once(): void
    {
        $variant = $this->variant();
        $this->addToCart();
        $ref = $this->checkout()->json('data.public_ref');
        $service = app(OrderExpiryService::class);

        $this->assertSame(0, $service->expireDue());
        $this->assertSame(2, $this->stock($variant)->reserved);

        $this->travel(61)->minutes();

        $this->assertSame(1, $service->expireDue());
        $this->assertSame(0, $service->expireDue());

        $order = Order::where('public_ref', $ref)->firstOrFail();
        $this->assertSame(Order::EXPIRED, $order->status);
        $this->assertSame('released', $order->reservations()->value('status'));
        $this->assertSame(0, $this->stock($variant)->reserved);
        $this->assertSame(10, $this->stock($variant)->quantity);
        $this->assertSame(2, $order->histories()->count());
    }

    public function test_expire_command_runs(): void
    {
        $this->artisan('orders:expire')->assertSuccessful();
    }

    public function test_reserved_stock_cannot_be_sold_again_or_adjusted_away(): void
    {
        $variant = $this->variant('NAS-01', 3);
        $this->addToCart('NAS-01', 3);
        $this->checkout()->assertCreated();

        $this->addToCart('NAS-01', 1);
        $this->checkout([], 'key-0000000000000002')->assertUnprocessable();

        $admin = User::factory()->create();
        $this->expectException(ValidationException::class);
        app(InventoryService::class)->adjust($this->warehouse->id, $variant->id, 'adjustment', -1, 'uji', $admin);
    }
}
