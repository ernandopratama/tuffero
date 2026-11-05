<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\CatalogDraftSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductStockAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function variantData(array $overrides = []): array
    {
        return ['sku' => 'NAS-TOP-01', 'name' => 'Toples', 'price' => 144000, 'compare_at_price' => 150000, 'weight_gram' => 500, ...$overrides];
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(['name' => 'Nastar', 'slug' => 'nastar-'.uniqid(), ...$attributes]);
    }

    private function variant(array $overrides = []): ProductVariant
    {
        return $this->product()->variants()->create($this->variantData($overrides));
    }

    private function adjustBody(ProductVariant $variant, int $change, string $type = 'adjustment'): array
    {
        return [
            'warehouse_id' => Warehouse::where('code', 'MLG')->value('id'),
            'product_variant_id' => $variant->id,
            'type' => $type,
            'quantity_change' => $change,
            'note' => 'Hitung ulang gudang',
        ];
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function accessProvider(): array
    {
        return [
            'marketing lihat produk' => ['marketing', 'getJson', '/api/v1/admin/products', 200],
            'gudang lihat produk' => ['warehouse', 'getJson', '/api/v1/admin/products', 200],
            'pemilik lihat stok' => ['owner', 'getJson', '/api/v1/admin/stock', 200],
            'cs lihat stok' => ['customer-service', 'getJson', '/api/v1/admin/stock', 200],
            'marketing tidak lihat stok' => ['marketing', 'getJson', '/api/v1/admin/stock', 403],
            'pemilik tidak ubah produk' => ['owner', 'postJson', '/api/v1/admin/products', 403],
            'cs tidak ubah produk' => ['customer-service', 'postJson', '/api/v1/admin/products', 403],
            'gudang tidak ubah produk' => ['warehouse', 'postJson', '/api/v1/admin/products', 403],
            'marketing tidak ubah produk' => ['marketing', 'postJson', '/api/v1/admin/products', 403],
            'pemilik tidak ubah stok' => ['owner', 'postJson', '/api/v1/admin/stock/adjust', 403],
            'cs tidak ubah stok' => ['customer-service', 'postJson', '/api/v1/admin/stock/adjust', 403],
        ];
    }

    #[DataProvider('accessProvider')]
    public function test_role_access(string $role, string $method, string $url, int $status): void
    {
        $this->actingAs($this->staff($role))->{$method}($url, [])->assertStatus($status);
    }

    public function test_guest_and_roleless_user_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/products')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/products')->assertForbidden();
    }

    public function test_writes_require_enrolled_mfa(): void
    {
        $this->actingAs($this->staff('store-admin', ['mfa_totp_confirmed_at' => null]))
            ->postJson('/api/v1/admin/products', ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_store_admin_manages_product_and_variant_with_audit(): void
    {
        $admin = $this->staff('store-admin');
        $this->actingAs($admin);

        $id = $this->postJson('/api/v1/admin/products', ['name' => 'Nastar Sultan', 'description' => 'Isi nanas'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'nastar-sultan')
            ->assertJsonPath('data.is_published', false)
            ->json('data.id');

        $this->postJson('/api/v1/admin/products', ['name' => 'Nastar Sultan'])->assertJsonPath('data.slug', 'nastar-sultan-2');

        $variantId = $this->postJson("/api/v1/admin/products/{$id}/variants", $this->variantData())
            ->assertCreated()
            ->assertJsonPath('data.variants.0.price', 144000)
            ->json('data.variants.0.id');

        $this->patchJson("/api/v1/admin/variants/{$variantId}", $this->variantData(['price' => 140000]))->assertOk();

        $change = AuditLog::where('action', 'variant.updated')->firstOrFail();
        $this->assertSame($admin->id, $change->actor_user_id);
        $this->assertSame(['old' => 144000, 'new' => 140000], $change->changes['price']);
    }

    public function test_badges_and_order_limit_are_editable_and_validated(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $product = $this->product();
        $variant = $product->variants()->create($this->variantData());

        $this->assertSame(50, $variant->fresh()->max_order_quantity);

        $this->patchJson("/api/v1/admin/products/{$product->id}", ['badges' => ['Terlaris', 'Edisi Ramadan']])
            ->assertOk()->assertJsonPath('data.badges', ['Terlaris', 'Edisi Ramadan']);
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['badges' => ['A', 'B', 'C', 'D', 'E', 'F']])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['badges' => ['Sama', 'Sama']])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['badges' => [str_repeat('x', 31)]])->assertUnprocessable();

        $this->patchJson("/api/v1/admin/variants/{$variant->id}", $this->variantData(['max_order_quantity' => 20]))
            ->assertOk()->assertJsonPath('data.variants.0.max_order_quantity', 20);
        $this->patchJson("/api/v1/admin/variants/{$variant->id}", $this->variantData(['max_order_quantity' => 0]))->assertUnprocessable();
        $this->patchJson("/api/v1/admin/variants/{$variant->id}", $this->variantData(['max_order_quantity' => 1000]))->assertUnprocessable();
    }

    public function test_publish_requires_an_active_variant(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $product = $this->product();

        $this->patchJson("/api/v1/admin/products/{$product->id}", ['is_published' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_published');

        $variant = $product->variants()->create($this->variantData(['is_active' => false]));
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['is_published' => true])->assertUnprocessable();

        $variant->update(['is_active' => true]);
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['is_published' => true])->assertOk();
        $this->assertTrue($product->fresh()->is_published);
    }

    public function test_last_active_variant_of_published_product_cannot_be_deactivated(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $product = $this->product(['is_published' => true]);
        $variant = $product->variants()->create($this->variantData());

        $this->patchJson("/api/v1/admin/variants/{$variant->id}", $this->variantData(['is_active' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $product->variants()->create($this->variantData(['sku' => 'NAS-TOP-02']));
        $this->patchJson("/api/v1/admin/variants/{$variant->id}", $this->variantData(['is_active' => false]))->assertOk();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidVariantProvider(): array
    {
        return [
            'harga nol' => [['price' => 0], 'price'],
            'harga minus' => [['price' => -1], 'price'],
            'harga desimal' => [['price' => 10.5], 'price'],
            'harga normal lebih kecil' => [['compare_at_price' => 100], 'compare_at_price'],
            'berat nol' => [['weight_gram' => 0], 'weight_gram'],
            'sku simbol' => [['sku' => 'A B<script>'], 'sku'],
        ];
    }

    #[DataProvider('invalidVariantProvider')]
    public function test_variant_validation(array $overrides, string $field): void
    {
        $this->actingAs($this->staff('store-admin'));

        $this->postJson("/api/v1/admin/products/{$this->product()->id}/variants", $this->variantData($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_sku_must_be_unique_including_soft_deleted(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $this->variant()->delete();

        $this->postJson("/api/v1/admin/products/{$this->product()->id}/variants", $this->variantData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');
    }

    public function test_image_upload_accepts_photo_and_rejects_svg_and_large_files(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff('store-admin'));
        $product = $this->product();
        $url = "/api/v1/admin/products/{$product->id}/images";

        $path = $this->postJson($url, ['image' => UploadedFile::fake()->image('a.jpg', 100, 100), 'alt' => 'Foto nastar'])
            ->assertCreated()
            ->json('data.images.0.url');
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $path));

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->postJson($url, ['image' => $svg, 'alt' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('image');

        $disguised = UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;');
        $this->postJson($url, ['image' => $disguised, 'alt' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('image');

        $large = UploadedFile::fake()->image('b.jpg')->size(3000);
        $this->postJson($url, ['image' => $large, 'alt' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->postJson($url, ['image' => UploadedFile::fake()->image('c.jpg'), 'alt' => ''])->assertUnprocessable()->assertJsonValidationErrors('alt');
    }

    public function test_image_delete_removes_file_and_is_audited(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff('store-admin'));
        $product = $this->product();
        $this->postJson("/api/v1/admin/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg'), 'alt' => 'Foto'])->assertCreated();
        $image = $product->images()->firstOrFail();

        $this->deleteJson("/api/v1/admin/images/{$image->id}")->assertOk()->assertJsonCount(0, 'data.images');

        Storage::disk('public')->assertMissing($image->path);
        $this->assertSame(1, AuditLog::where('action', 'product.image_removed')->count());
    }

    public function test_warehouse_can_adjust_stock_and_movement_is_recorded(): void
    {
        $user = $this->staff('warehouse');
        $variant = $this->variant();
        $this->actingAs($user);

        $this->postJson('/api/v1/admin/stock/adjust', $this->adjustBody($variant, 10, 'restock'))
            ->assertOk()
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.available', 10);

        $this->postJson('/api/v1/admin/stock/adjust', $this->adjustBody($variant, -3))->assertJsonPath('data.quantity', 7);

        $stock = InventoryStock::where('product_variant_id', $variant->id)->firstOrFail();
        $movement = $stock->movements()->latest('id')->firstOrFail();

        $this->assertSame(-3, $movement->quantity_change);
        $this->assertSame(7, $movement->quantity_after);
        $this->assertSame($user->id, $movement->actor_user_id);
        $this->assertSame('Hitung ulang gudang', $movement->note);
        $this->assertSame(2, AuditLog::where('action', 'stock.adjusted')->count());

        $this->getJson('/api/v1/admin/stock/movements?'.http_build_query(['warehouse_id' => $stock->warehouse_id, 'variant_id' => $variant->id]))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.actor', $user->name);
    }

    public function test_stock_cannot_go_negative_or_below_reserved(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $variant = $this->variant();

        $this->postJson('/api/v1/admin/stock/adjust', $this->adjustBody($variant, -1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_change');

        $this->postJson('/api/v1/admin/stock/adjust', $this->adjustBody($variant, 5, 'restock'))->assertOk();
        InventoryStock::where('product_variant_id', $variant->id)->update(['reserved' => 3]);

        $this->postJson('/api/v1/admin/stock/adjust', $this->adjustBody($variant, -3))->assertUnprocessable();

        $stock = InventoryStock::where('product_variant_id', $variant->id)->firstOrFail();
        $this->assertSame(5, $stock->quantity);
        $this->assertSame(1, $stock->movements()->count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidAdjustProvider(): array
    {
        return [
            'alasan kosong' => [['note' => ''], 'note'],
            'jumlah nol' => [['quantity_change' => 0], 'quantity_change'],
            'jenis asing' => [['type' => 'sale'], 'type'],
            'stok masuk minus' => [['type' => 'restock', 'quantity_change' => -1], 'quantity_change'],
            'gudang tidak ada' => [['warehouse_id' => 99999], 'warehouse_id'],
        ];
    }

    #[DataProvider('invalidAdjustProvider')]
    public function test_adjust_validation(array $overrides, string $field): void
    {
        $this->actingAs($this->staff('warehouse'));

        $this->postJson('/api/v1/admin/stock/adjust', [...$this->adjustBody($this->variant(), 1), ...$overrides])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_inactive_warehouse_cannot_receive_adjustment(): void
    {
        $this->actingAs($this->staff('warehouse'));
        $inactive = Warehouse::create(['code' => 'JKT', 'name' => 'Jakarta', 'city' => 'Jakarta', 'is_active' => false]);

        $this->postJson('/api/v1/admin/stock/adjust', [...$this->adjustBody($this->variant(), 1), 'warehouse_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warehouse_id');
    }

    public function test_stock_list_shows_zero_for_new_variant_and_hides_price(): void
    {
        $this->variant();

        $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/stock?search=NAS')
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 0)
            ->assertJsonPath('data.warehouses.0.code', 'MLG')
            ->assertJsonMissingPath('data.items.0.price');
    }

    public function test_catalog_import_creates_drafts_without_stock_and_can_run_again(): void
    {
        Storage::fake('public');

        $this->seed(CatalogDraftSeeder::class);

        $this->assertSame(29, Product::count());
        $this->assertSame(0, Product::where('is_published', true)->count());
        $this->assertSame(0, InventoryStock::count());

        $sultan = ProductVariant::where('sku', 'NAS-TOP-01')->firstOrFail();
        $this->assertSame(144000, $sultan->price);
        $this->assertSame(150000, $sultan->compare_at_price);
        $this->assertSame(21, $sultan->shelf_life_days);
        $this->assertNull(ProductVariant::where('sku', 'COO-BIT-02')->value('compare_at_price'));

        $image = $sultan->product->images()->firstOrFail();
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame('Nastar Sultan Nanas Premium', $image->alt);

        $sultan->update(['price' => 100000]);
        $this->seed(CatalogDraftSeeder::class);

        $this->assertSame(29, Product::count());
        $this->assertSame(100000, $sultan->fresh()->price);
    }

    public function test_service_rejects_change_directly_too(): void
    {
        $this->expectException(ValidationException::class);

        app(InventoryService::class)->adjust(Warehouse::firstOrFail()->id, $this->variant()->id, 'adjustment', -1, 'x', $this->staff('warehouse'));
    }
}
