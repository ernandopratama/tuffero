<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Models\VariantDiscount;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Services\DiscountService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Diskon produk per varian (D-066, D-070): harga efektif dihitung server per segmen, tanpa menimpa harga jual.
 * Varian uji: Rp100.000, ongkir uji Rp12.000 per kg.
 */
class ProductDiscountTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar Uji', 'slug' => 'nastar-uji', 'is_published' => true, 'product_category_id' => ProductCategory::where('slug', 'nastar')->value('id')]);
        $this->variant = $product->variants()->create(['sku' => 'NAS-UJI-01', 'name' => 'Standar', 'price' => 100000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $this->variant->id, 'quantity' => 20]);
    }

    private function discount(array $attributes = []): VariantDiscount
    {
        return $this->variant->discounts()->create([
            'audience' => 'all', 'type' => 'percent', 'value' => 20, 'is_active' => true, ...$attributes,
        ]);
    }

    private function staff(string $role = 'store-admin'): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function cartPrice(): int
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-UJI-01', 'quantity' => 2])->assertOk();

        return $this->getJson('/api/v1/cart')->json('data.items.0.price');
    }

    public function test_percent_and_fixed_discounts_lower_the_price_in_cart_and_catalog(): void
    {
        $this->discount();

        $this->assertSame(80000, $this->cartPrice());
        $this->assertSame(160000, $this->getJson('/api/v1/cart')->json('data.subtotal'));
        $this->getJson('/api/v1/catalog')->assertJsonPath('data.products.0.variants.0.price', 80000)->assertJsonPath('data.products.0.variants.0.list_price', 100000);

        VariantDiscount::query()->delete();
        $this->discount(['type' => 'fixed', 'value' => 15000]);
        $this->assertSame(85000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));
    }

    public function test_the_discount_is_checked_and_stored_on_the_order_at_checkout_time(): void
    {
        $this->discount();
        $this->cartPrice();

        $this->withHeader('Idempotency-Key', 'key-0000000000000001')->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi', 'customer_email' => 'b@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated();

        $order = Order::with('items')->firstOrFail();
        $this->assertSame(80000, $order->items->first()->unit_price);
        $this->assertSame(160000, $order->subtotal);
        $this->assertSame(0, $order->discount_total);
        $this->assertSame(172000, $order->total);
        // Harga jual varian di database tidak berubah.
        $this->assertSame(100000, $this->variant->fresh()->price);
    }

    public function test_segments_split_non_member_and_member_prices(): void
    {
        $this->discount(['audience' => 'guest', 'value' => 10]);
        $this->discount(['audience' => 'member', 'value' => 30]);

        $this->assertSame(90000, $this->cartPrice());

        $this->actingAs(User::factory()->create());
        $this->assertSame(70000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));
    }

    public function test_staff_account_sees_the_non_member_price(): void
    {
        $this->discount(['audience' => 'member', 'value' => 30]);

        $this->actingAs($this->staff());

        $this->assertSame(100000, $this->cartPrice());
    }

    public function test_active_discounts_stack_by_adding_their_savings_from_the_list_price(): void
    {
        $this->discount(['value' => 10]);
        $this->discount(['type' => 'fixed', 'value' => 25000]);
        $this->discount(['value' => 5]);

        // 10.000 + 25.000 + 5.000 = 40.000, di bawah batas 50%.
        $this->assertSame(60000, $this->cartPrice());
    }

    public function test_stacked_discounts_are_capped_at_the_configured_percentage(): void
    {
        $this->discount(['value' => 30]);
        $this->discount(['value' => 30]);

        $this->assertSame(50000, $this->cartPrice());

        Setting::where('key', 'discount.max_percent')->update(['value' => '70']);
        $this->assertSame(40000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));
    }

    public function test_default_cap_is_fifty_percent_when_the_setting_is_missing(): void
    {
        Setting::where('key', 'discount.max_percent')->delete();
        $this->discount(['value' => 30]);
        $this->discount(['value' => 30]);

        $this->assertSame(50000, $this->cartPrice());
    }

    /** @return array<string, array{string, int}> */
    public static function capEditors(): array
    {
        return ['super admin boleh' => ['super-admin', 200], 'admin toko boleh' => ['store-admin', 200], 'marketing tidak' => ['marketing', 403], 'cs tidak' => ['customer-service', 403]];
    }

    #[DataProvider('capEditors')]
    public function test_only_super_admin_and_store_admin_change_the_cap(string $role, int $status): void
    {
        $this->actingAs($this->staff($role))->patchJson('/api/v1/admin/settings/discount.max_percent', ['value' => 40])->assertStatus($status);

        $this->assertSame($status === 200 ? '40' : '50', Setting::where('key', 'discount.max_percent')->value('value'));
    }

    public function test_cap_must_stay_between_one_and_ninety(): void
    {
        $admin = $this->staff();

        foreach ([0, 91, 'abc'] as $value) {
            $this->actingAs($admin)->patchJson('/api/v1/admin/settings/discount.max_percent', ['value' => $value])->assertUnprocessable();
        }
    }

    public function test_a_single_discount_above_the_cap_is_rejected_and_follows_a_changed_cap(): void
    {
        $admin = $this->staff();
        $body = ['audience' => 'all', 'type' => 'percent', 'value' => 60, 'is_active' => true];

        $this->actingAs($admin)->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", $body)->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", [...$body, 'type' => 'fixed', 'value' => 50001])->assertUnprocessable();
        $this->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", [...$body, 'type' => 'fixed', 'value' => 50000])->assertCreated();

        Setting::where('key', 'discount.max_percent')->update(['value' => '70']);
        $this->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", $body)->assertCreated();
        $this->getJson("/api/v1/admin/products/{$this->variant->product_id}")->assertJsonPath('data.max_discount_percent', 70);
    }

    public function test_inactive_not_started_and_expired_discounts_are_ignored(): void
    {
        $this->discount(['is_active' => false]);
        $this->discount(['starts_at' => now()->addDay()]);
        $this->discount(['ends_at' => now()->subMinute()]);

        $this->assertSame(100000, $this->cartPrice());

        $this->discount(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->assertSame(80000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));
    }

    public function test_the_price_never_drops_below_one_rupiah_even_with_a_tiny_variant(): void
    {
        $tiny = $this->variant->product->variants()->create(['sku' => 'TINY-01', 'name' => 'Kecil', 'price' => 1, 'weight_gram' => 100]);
        $tiny->discounts()->create(['audience' => 'all', 'type' => 'fixed', 'value' => 500000, 'is_active' => true]);

        $this->assertSame([$tiny->id => 1], app(DiscountService::class)->prices([$tiny], null));
    }

    public function test_storefront_shows_the_discounted_price_with_the_list_price_struck_through(): void
    {
        $this->discount();

        $this->get('/produk/nas-uji-01')->assertOk()->assertSee('Rp80.000')->assertSee('<s>Rp100.000</s>', false);
        $this->get('/produk')->assertOk()->assertSee('Rp80.000')->assertSee('<s>Rp100.000</s>', false)->assertSee('-20%');
    }

    public function test_cart_reports_the_list_price_and_the_total_saving_for_display_only(): void
    {
        $this->discount();
        $this->cartPrice();

        $this->getJson('/api/v1/cart')
            ->assertJsonPath('data.items.0.normal', 100000)
            ->assertJsonPath('data.items.0.price', 80000)
            ->assertJsonPath('data.subtotal', 160000)
            ->assertJsonPath('data.saving', 40000);
    }

    public function test_cart_without_a_discount_has_no_saving(): void
    {
        $this->cartPrice();

        $this->getJson('/api/v1/cart')->assertJsonPath('data.saving', 0)->assertJsonPath('data.items.0.normal', 100000);
    }

    public function test_manual_compare_price_counts_as_the_struck_price(): void
    {
        $this->variant->update(['compare_at_price' => 125000]);
        $this->cartPrice();

        $this->getJson('/api/v1/cart')->assertJsonPath('data.items.0.normal', 125000)->assertJsonPath('data.saving', 50000)->assertJsonPath('data.subtotal', 200000);
    }

    public function test_voucher_minimum_is_checked_against_the_discounted_subtotal(): void
    {
        $this->discount();
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-UJI-01', 'quantity' => 2])->assertOk();
        Voucher::create(['code' => 'MIN180', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 180000, 'is_active' => true]);

        // Subtotal setelah diskon 160.000, di bawah minimum 180.000 walau harga normal 200.000.
        $this->postJson('/api/v1/vouchers/check', ['code' => 'MIN180'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_min_subtotal');
    }

    public function test_admin_creates_updates_and_deactivates_a_discount_with_audit(): void
    {
        $admin = $this->staff();

        $id = $this->actingAs($admin)->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", [
            'audience' => 'member', 'type' => 'percent', 'value' => 15, 'is_active' => true,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/admin/discounts/{$id}", ['audience' => 'member', 'type' => 'percent', 'value' => 25, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.value', 25)->assertJsonPath('data.is_active', false);

        $this->getJson("/api/v1/admin/products/{$this->variant->product_id}")
            ->assertJsonPath('data.variants.0.discounts.0.audience', 'member')->assertJsonPath('data.variants.0.discounts.0.value', 25);
        $this->assertSame(['discount.updated', 'discount.created'], AuditLog::whereIn('action', ['discount.created', 'discount.updated'])->orderByDesc('id')->pluck('action')->all());
    }

    /** @return array<string, array<string, mixed>> */
    public static function invalidDiscounts(): array
    {
        return [
            'persen di atas batas 50' => [['type' => 'percent', 'value' => 51], 'value'],
            'potongan sama dengan harga' => [['type' => 'fixed', 'value' => 100000], 'value'],
            'nilai nol' => [['type' => 'percent', 'value' => 0], 'value'],
            'segmen salah' => [['audience' => 'vip'], 'audience'],
            'selesai sebelum mulai' => [['starts_at' => '2030-01-02', 'ends_at' => '2030-01-01'], 'ends_at'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidDiscounts')]
    public function test_invalid_discounts_are_rejected(array $overrides, string $field): void
    {
        $body = ['audience' => 'all', 'type' => 'percent', 'value' => 10, 'is_active' => true, ...$overrides];

        $this->actingAs($this->staff())->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", $body)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{string, int}> */
    public static function roleAccess(): array
    {
        return ['admin toko boleh' => ['store-admin', 201], 'marketing tidak' => ['marketing', 403], 'gudang tidak' => ['warehouse', 403], 'cs tidak' => ['customer-service', 403]];
    }

    #[DataProvider('roleAccess')]
    public function test_only_roles_that_manage_products_can_create_discounts(string $role, int $status): void
    {
        $this->actingAs($this->staff($role))->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", [
            'audience' => 'all', 'type' => 'percent', 'value' => 10, 'is_active' => true,
        ])->assertStatus($status);
    }

    public function test_guest_cannot_reach_the_admin_endpoints(): void
    {
        $this->postJson("/api/v1/admin/variants/{$this->variant->id}/discounts", [])->assertUnauthorized();
    }
}
