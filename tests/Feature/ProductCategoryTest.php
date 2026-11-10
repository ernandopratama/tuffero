<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kategori dan jenis produk: Super Admin dan Admin Toko mengelola; toko membaca dari database.
 */
class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function category(string $slug): ProductCategory
    {
        return ProductCategory::where('slug', $slug)->firstOrFail();
    }

    private function product(array $product = []): Product
    {
        $model = Product::create(['name' => 'Nastar Uji', 'slug' => 'nastar-uji', ...$product]);
        $model->variants()->create(['sku' => 'NAS-UJI-01', 'name' => 'Standar', 'price' => 123000, 'weight_gram' => 500]);

        return $model;
    }

    public function test_migration_seeds_the_seven_agreed_categories_in_order(): void
    {
        $this->assertSame(
            ['Nastar', 'Macaron', 'Pia', 'Cookies', 'Brownies & Cake', 'Hampers', 'Produk Lainnya'],
            ProductCategory::orderBy('sort_order')->pluck('name_id')->all(),
        );
        $this->assertSame(['Sultan', 'Classic', 'Apel', 'Cookies', 'Paket'], $this->category('nastar')->types->pluck('name_id')->all());
    }

    /** @return array<string, array{string, int}> */
    public static function roleAccess(): array
    {
        return [
            'super admin boleh' => ['super-admin', 201],
            'admin toko boleh' => ['store-admin', 201],
            'pemilik tidak' => ['owner', 403],
            'cs tidak' => ['customer-service', 403],
            'gudang tidak' => ['warehouse', 403],
            'marketing tidak' => ['marketing', 403],
        ];
    }

    #[DataProvider('roleAccess')]
    public function test_only_super_admin_and_store_admin_create_categories(string $role, int $status): void
    {
        $this->actingAs($this->staff($role))
            ->postJson('/api/v1/admin/categories', ['name_id' => 'Minuman', 'name_en' => 'Drinks'])
            ->assertStatus($status);
    }

    public function test_staff_with_product_access_can_read_but_guests_cannot(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
        $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.items.0.slug', 'nastar')
            ->assertJsonPath('data.items.0.types.0.slug', 'sultan');
    }

    public function test_writes_require_enrolled_mfa(): void
    {
        $this->actingAs($this->staff('store-admin', ['mfa_totp_confirmed_at' => null]))
            ->postJson('/api/v1/admin/categories', ['name_id' => 'Minuman', 'name_en' => 'Drinks'])
            ->assertForbidden();
    }

    public function test_category_color_comes_from_the_fixed_palette(): void
    {
        $this->actingAs($this->staff('store-admin'));

        $this->assertNotNull($this->category('nastar')->color, 'Migration memberi kategori awal warna.');

        $id = $this->postJson('/api/v1/admin/categories', ['name_id' => 'Minuman', 'name_en' => 'Drinks', 'color' => 'teal'])
            ->assertCreated()
            ->assertJsonPath('data.color', 'teal')
            ->json('data.id');

        $this->patchJson("/api/v1/admin/categories/{$id}", ['color' => 'rose'])->assertOk()->assertJsonPath('data.color', 'rose');
        $this->patchJson("/api/v1/admin/categories/{$id}", ['color' => '#ff0000'])->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->patchJson("/api/v1/admin/categories/{$id}", ['color' => 'url(javascript:1)'])->assertUnprocessable();
    }

    public function test_create_and_update_category_and_type_with_audit(): void
    {
        $admin = $this->staff('store-admin');
        $this->actingAs($admin);

        $id = $this->postJson('/api/v1/admin/categories', ['name_id' => 'Minuman Segar', 'name_en' => 'Fresh Drinks'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'minuman-segar')
            ->assertJsonPath('data.sort_order', 8)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->postJson('/api/v1/admin/categories', ['name_id' => 'Minuman Segar', 'name_en' => 'Dup'])
            ->assertUnprocessable()->assertJsonValidationErrors('name_id');

        $this->patchJson("/api/v1/admin/categories/{$id}", ['name_en' => 'Cold Drinks'])->assertOk()->assertJsonPath('data.name_en', 'Cold Drinks');

        $typeId = $this->postJson("/api/v1/admin/categories/{$id}/types", ['name_id' => 'Teh', 'name_en' => 'Tea'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'teh')
            ->json('data.id');

        // Nama jenis boleh sama di kategori lain, tetapi tidak di kategori yang sama.
        $this->postJson("/api/v1/admin/categories/{$id}/types", ['name_id' => 'Teh', 'name_en' => 'Tea'])->assertUnprocessable()->assertJsonValidationErrors('name_id');
        $this->postJson('/api/v1/admin/categories/'.$this->category('nastar')->id.'/types', ['name_id' => 'Teh', 'name_en' => 'Tea'])->assertCreated()->assertJsonPath('data.slug', 'teh-2');

        $this->patchJson("/api/v1/admin/category-types/{$typeId}", ['name_id' => 'Teh Manis'])->assertOk()->assertJsonPath('data.name_id', 'Teh Manis');

        $this->assertSame(
            ['category.created', 'category.updated', 'category.type_created', 'category.type_created', 'category.type_updated'],
            AuditLog::orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame($admin->id, AuditLog::where('action', 'category.updated')->value('actor_user_id'));
    }

    public function test_deactivating_is_blocked_while_published_products_use_it(): void
    {
        $nastar = $this->category('nastar');
        $sultan = ProductType::where('slug', 'sultan')->firstOrFail();
        $this->product(['is_published' => true, 'product_category_id' => $nastar->id, 'product_type_id' => $sultan->id]);
        $this->actingAs($this->staff('super-admin'));

        $this->patchJson("/api/v1/admin/categories/{$nastar->id}", ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->patchJson("/api/v1/admin/category-types/{$sultan->id}", ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->patchJson('/api/v1/admin/categories/'.$this->category('hampers')->id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_product_category_and_type_rules(): void
    {
        $nastar = $this->category('nastar');
        $macaron = $this->category('macaron');
        $sultan = ProductType::where('slug', 'sultan')->firstOrFail();
        $product = $this->product();
        $this->actingAs($this->staff('store-admin'));

        // Tayang butuh kategori.
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['is_published' => true])->assertUnprocessable()->assertJsonValidationErrors('product_category_id');

        // Jenis harus milik kategorinya.
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['product_category_id' => $macaron->id, 'product_type_id' => $sultan->id])
            ->assertUnprocessable()->assertJsonValidationErrors('product_type_id');

        $this->patchJson("/api/v1/admin/products/{$product->id}", ['product_category_id' => $nastar->id, 'product_type_id' => $sultan->id, 'is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.product_category_id', $nastar->id)
            ->assertJsonPath('data.product_type_id', $sultan->id);

        // Ganti kategori tanpa menyebut jenis melepas jenis lama.
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['product_category_id' => $macaron->id])
            ->assertOk()->assertJsonPath('data.product_type_id', null);

        // Produk tayang tidak boleh dikosongkan kategorinya.
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['product_category_id' => null])->assertUnprocessable()->assertJsonValidationErrors('product_category_id');

        // Kategori nonaktif tidak bisa dipilih.
        $this->category('hampers')->update(['is_active' => false]);
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['product_category_id' => $this->category('hampers')->id])->assertUnprocessable()->assertJsonValidationErrors('product_category_id');
    }

    public function test_store_reads_categories_and_types_from_the_database(): void
    {
        $nastar = $this->category('nastar');
        $this->product(['is_published' => true, 'product_category_id' => $nastar->id, 'product_type_id' => ProductType::where('slug', 'sultan')->value('id')]);

        $html = $this->get('/produk')->assertOk()->getContent();

        // Hanya kategori yang punya produk tayang; Hampers kosong tidak tampil.
        $this->assertStringContainsString('<span>Nastar</span>', $html);
        $this->assertStringNotContainsString('<span>Hampers</span>', $html);
        $this->assertStringContainsString('"categories":["nastar"],', $html);
        $this->assertStringContainsString('"cats":{"nastar":"Nastar"},"types":{"sultan":"Sultan"}', $html);

        $this->get('/produk/nas-uji-01')->assertOk()->assertSee('tf-pd-badge', false)->assertSee('Nastar');
        $this->get('/produk/nas-uji-01?lang=en')->assertOk()->assertSee('Nastar');
    }

    public function test_inactive_category_is_hidden_from_store_but_product_still_sells(): void
    {
        $category = ProductCategory::create(['slug' => 'musiman', 'name_id' => 'Musiman', 'name_en' => 'Seasonal', 'is_active' => true]);
        $this->product(['is_published' => true, 'product_category_id' => $category->id]);
        $category->update(['is_active' => false]);

        $this->get('/produk')->assertOk()->assertDontSee('<span>Musiman</span>', false);
        $this->get('/produk/nas-uji-01')->assertOk();
    }
}
