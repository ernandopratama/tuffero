<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantDiscount;
use App\Models\Voucher;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kampanye promo dan flash sale (D-073): periode, diskon produk dan voucher milik kampanye, halaman Promo.
 * Varian uji: Rp100.000.
 */
class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar Uji', 'slug' => 'nastar-uji', 'is_published' => true]);
        $this->variant = $product->variants()->create(['sku' => 'NAS-UJI-01', 'name' => 'Standar', 'price' => 100000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $this->variant->id, 'quantity' => 50]);
    }

    private function staff(string $role = 'store-admin'): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function campaign(array $attributes = []): Campaign
    {
        $campaign = new Campaign([
            'title' => 'Promo Imlek', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'is_flash' => false, 'is_active' => true, ...$attributes,
        ]);
        $campaign->forceFill(['slug' => $attributes['slug'] ?? 'promo-imlek'])->save();

        return $campaign;
    }

    private function item(Campaign $campaign, array $attributes = []): VariantDiscount
    {
        $item = new VariantDiscount(['audience' => 'all', 'type' => 'percent', 'value' => 20, 'is_active' => true, ...$attributes]);
        $item->forceFill(['campaign_id' => $campaign->id, 'product_variant_id' => $this->variant->id])->save();

        return $item;
    }

    private function cartPrice(): int
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-UJI-01', 'quantity' => 2])->assertOk();

        return $this->getJson('/api/v1/cart')->json('data.items.0.price');
    }

    public function test_a_campaign_discount_applies_only_while_the_campaign_is_live(): void
    {
        $campaign = $this->campaign();
        $this->item($campaign);

        $this->assertSame(80000, $this->cartPrice());

        $campaign->update(['is_active' => false]);
        $this->assertSame(100000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));

        $campaign->update(['is_active' => true, 'starts_at' => now()->addHour(), 'ends_at' => now()->addDay()]);
        $this->assertSame(100000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));

        $campaign->update(['starts_at' => now()->subDay(), 'ends_at' => now()->subMinute()]);
        $this->assertSame(100000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));
    }

    public function test_campaign_discount_stacks_with_a_regular_discount_up_to_the_cap(): void
    {
        $this->variant->discounts()->create(['audience' => 'all', 'type' => 'percent', 'value' => 20, 'is_active' => true]);
        $this->item($this->campaign(), ['value' => 40]);

        $this->assertSame(50000, $this->cartPrice());
    }

    public function test_campaign_segments_apply_to_the_right_customers(): void
    {
        $campaign = $this->campaign();
        $this->item($campaign, ['audience' => 'member', 'value' => 30]);

        $this->assertSame(100000, $this->cartPrice());

        $this->actingAs(User::factory()->create());
        $this->assertSame(70000, $this->getJson('/api/v1/cart')->json('data.items.0.price'));
    }

    public function test_promo_pages_list_visible_campaigns_and_hide_drafts_and_ended_ones(): void
    {
        $this->campaign();
        $this->campaign(['title' => 'Segera', 'slug' => 'segera', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $this->campaign(['title' => 'Draf Rahasia', 'slug' => 'draf', 'is_active' => false]);
        $this->campaign(['title' => 'Sudah Lewat', 'slug' => 'lewat', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

        $this->get('/promo')->assertOk()->assertSee('Promo Imlek')->assertSee('Segera')->assertDontSee('Draf Rahasia')->assertDontSee('Sudah Lewat');
        $this->get('/promo/promo-imlek')->assertOk();
        $this->get('/promo/segera')->assertOk();
        $this->get('/promo/draf')->assertNotFound();
        $this->get('/promo/lewat')->assertNotFound();
        $this->get('/promo/tidak-ada')->assertNotFound();
    }

    public function test_promo_page_shows_discounted_products_and_a_countdown(): void
    {
        $campaign = $this->campaign(['description' => 'Diskon spesial Imlek']);
        $this->item($campaign);

        $this->get('/promo/promo-imlek')->assertOk()
            ->assertSee('Diskon spesial Imlek')
            ->assertSee('Nastar Uji')
            ->assertSee('Rp80.000')
            ->assertSee('<s>Rp100.000</s>', false)
            ->assertSee('-20%')
            ->assertSee('data-end', false)
            ->assertDontSee('Flash Sale');
    }

    public function test_an_upcoming_campaign_shows_normal_prices_until_it_starts(): void
    {
        $campaign = $this->campaign(['slug' => 'segera', 'starts_at' => now()->addHour(), 'ends_at' => now()->addDay()]);
        $this->item($campaign);

        $this->get('/promo/segera')->assertOk()->assertSee('Segera mulai')->assertSee('Rp100.000')->assertDontSee('Rp80.000');
    }

    public function test_flash_sale_shows_the_label_and_units_sold_in_its_period_only(): void
    {
        $campaign = $this->campaign(['is_flash' => true]);
        $this->item($campaign);
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-UJI-01', 'quantity' => 3])->assertOk();
        $this->withHeader('Idempotency-Key', 'key-0000000000000001')->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi', 'customer_email' => 'b@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated();

        $this->get('/promo/promo-imlek')->assertOk()->assertSee('Flash Sale')->assertSee('Terjual 3');

        // Pesanan batal tidak dihitung terjual, dan pesanan sebelum periode tidak ikut.
        Order::query()->update(['status' => Order::CANCELLED]);
        $this->get('/promo/promo-imlek')->assertOk()->assertDontSee('Terjual');
        Order::query()->update(['status' => Order::PAID, 'created_at' => now()->subDays(5)]);
        $this->get('/promo/promo-imlek')->assertOk()->assertDontSee('Terjual');
    }

    public function test_a_normal_campaign_never_shows_units_sold(): void
    {
        $this->item($this->campaign());

        $this->get('/promo/promo-imlek')->assertOk()->assertDontSee('Terjual');
    }

    public function test_campaign_vouchers_work_only_while_live_and_are_listed_on_the_campaign_page_api(): void
    {
        $campaign = $this->campaign();
        $voucher = Voucher::create(['code' => 'IMLEK10', 'type' => 'fixed', 'value' => 10000, 'min_subtotal' => 0, 'is_active' => true, 'requires_claim' => true, 'campaign_id' => $campaign->id]);
        Voucher::create(['code' => 'UMUM', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0, 'is_active' => true, 'requires_claim' => true]);

        $this->assertSame(['IMLEK10'], collect($this->getJson('/api/v1/vouchers/claimable?campaign=promo-imlek')->json('data.vouchers'))->pluck('code')->all());
        $this->assertSame([], $this->getJson('/api/v1/vouchers/claimable?campaign=tidak-ada')->json('data.vouchers'));
        $this->assertEqualsCanonicalizing(['IMLEK10', 'UMUM'], collect($this->getJson('/api/v1/vouchers/claimable')->json('data.vouchers'))->pluck('code')->all());

        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/account/vouchers/IMLEK10/claim')->assertOk();
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-UJI-01', 'quantity' => 2])->assertOk();
        $this->postJson('/api/v1/vouchers/check', ['code' => 'IMLEK10'])->assertOk();

        $campaign->update(['ends_at' => now()->subMinute()]);
        $this->postJson('/api/v1/vouchers/check', ['code' => 'IMLEK10'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_invalid');
        $this->assertSame(['UMUM'], collect($this->getJson('/api/v1/vouchers/claimable')->json('data.vouchers'))->pluck('code')->all());
        $this->assertSame(0, $voucher->fresh()->used_count);
    }

    public function test_promo_page_renders_in_english(): void
    {
        $this->item($this->campaign());

        $this->get('/promo/promo-imlek?lang=en')->assertOk()->assertSee('Running now')->assertSee('Promo products');
        $this->get('/promo?lang=en')->assertOk()->assertSee('Promotions | Tuffero Bakery', false);
    }

    public function test_admin_creates_a_campaign_adds_items_and_everything_is_audited(): void
    {
        $admin = $this->staff();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/campaigns', [
            'title' => 'Flash Sale 11.11', 'description' => 'Hari belanja', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(),
            'is_flash' => true, 'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.slug', 'flash-sale-1111')->assertJsonPath('data.status', 'upcoming')->json('data.id');

        $this->postJson("/api/v1/admin/campaigns/{$id}/items", ['variant_id' => $this->variant->id, 'audience' => 'all', 'type' => 'percent', 'value' => 25, 'is_active' => true])
            ->assertCreated()->assertJsonPath('data.items.0.sku', 'NAS-UJI-01')->assertJsonPath('data.items_count', 1);
        $this->postJson("/api/v1/admin/campaigns/{$id}/items", ['variant_id' => $this->variant->id, 'audience' => 'all', 'type' => 'percent', 'value' => 10, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('variant_id');

        $itemId = VariantDiscount::where('campaign_id', $id)->value('id');
        $this->patchJson("/api/v1/admin/campaign-items/{$itemId}", ['audience' => 'all', 'type' => 'percent', 'value' => 30, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.items.0.value', 30)->assertJsonPath('data.items.0.is_active', false);

        $this->patchJson("/api/v1/admin/campaigns/{$id}", ['title' => 'Flash Sale 11.11', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(), 'is_flash' => true, 'is_active' => true])
            ->assertOk()->assertJsonPath('data.status', 'live');

        $this->assertEqualsCanonicalizing(['campaign.created', 'campaign.item_added', 'campaign.item_updated', 'campaign.updated'], AuditLog::where('action', 'like', 'campaign.%')->pluck('action')->all());
        $this->getJson('/api/v1/admin/campaigns')->assertOk()->assertJsonPath('data.items.0.title', 'Flash Sale 11.11');
    }

    public function test_slugs_stay_unique_and_item_values_follow_the_discount_cap(): void
    {
        $admin = $this->staff();
        $body = ['title' => 'Promo Sama', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(), 'is_flash' => false, 'is_active' => false];

        $first = $this->actingAs($admin)->postJson('/api/v1/admin/campaigns', $body)->assertCreated();
        $second = $this->postJson('/api/v1/admin/campaigns', $body)->assertCreated();
        $this->assertSame('promo-sama', $first->json('data.slug'));
        $this->assertSame('promo-sama-2', $second->json('data.slug'));

        $this->postJson('/api/v1/admin/campaigns/'.$first->json('data.id').'/items', ['variant_id' => $this->variant->id, 'audience' => 'all', 'type' => 'percent', 'value' => 60, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson('/api/v1/admin/campaigns', [...$body, 'ends_at' => now()->subDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_banner_upload_accepts_images_only(): void
    {
        Storage::fake('public');
        $campaign = $this->campaign();
        $this->actingAs($this->staff());

        $this->post("/api/v1/admin/campaigns/{$campaign->id}/image", ['image' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post("/api/v1/admin/campaigns/{$campaign->id}/image", ['image' => UploadedFile::fake()->image('banner.jpg', 1200, 400)], ['Accept' => 'application/json'])->assertOk();

        $path = $campaign->fresh()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->get('/promo')->assertOk()->assertSee('/storage/'.$path, false);
    }

    /** @return array<string, array{string, int}> */
    public static function roles(): array
    {
        return ['admin toko boleh' => ['store-admin', 201], 'marketing tidak' => ['marketing', 403], 'cs tidak' => ['customer-service', 403], 'gudang tidak' => ['warehouse', 403]];
    }

    #[DataProvider('roles')]
    public function test_only_super_admin_and_store_admin_manage_campaigns(string $role, int $status): void
    {
        $this->actingAs($this->staff($role))->postJson('/api/v1/admin/campaigns', [
            'title' => 'Uji', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(), 'is_flash' => false, 'is_active' => false,
        ])->assertStatus($status);
    }

    public function test_guests_and_customers_cannot_reach_the_admin_campaign_endpoints(): void
    {
        $this->getJson('/api/v1/admin/campaigns')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/campaigns')->assertForbidden();
    }

    public function test_variant_search_finds_by_sku_or_name_and_voucher_can_be_tied_to_a_campaign(): void
    {
        $admin = $this->staff();
        $campaign = $this->campaign();

        $this->actingAs($admin)->getJson('/api/v1/admin/campaign-variants?search=NAS-UJI')->assertOk()->assertJsonPath('data.items.0.sku', 'NAS-UJI-01')->assertJsonPath('data.items.0.label', 'Nastar Uji');
        $this->getJson('/api/v1/admin/campaign-variants?search=tidakada')->assertOk()->assertJsonCount(0, 'data.items');

        $this->postJson('/api/v1/admin/vouchers', ['code' => 'KAMPANYE5', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0, 'is_active' => true, 'campaign_id' => $campaign->id])->assertCreated();
        $this->assertSame($campaign->id, Voucher::where('code', 'KAMPANYE5')->value('campaign_id'));
        $this->postJson('/api/v1/admin/vouchers', ['code' => 'SALAHKAMP', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0, 'is_active' => true, 'campaign_id' => 99999])->assertUnprocessable()->assertJsonValidationErrors('campaign_id');
    }

    public function test_campaign_items_do_not_show_in_the_product_page_discount_list(): void
    {
        $this->item($this->campaign());
        $this->variant->discounts()->create(['audience' => 'guest', 'type' => 'percent', 'value' => 5, 'is_active' => true]);

        $this->actingAs($this->staff())->getJson("/api/v1/admin/products/{$this->variant->product_id}")
            ->assertOk()->assertJsonCount(1, 'data.variants.0.discounts')->assertJsonPath('data.variants.0.discounts.0.audience', 'guest');
    }
}
