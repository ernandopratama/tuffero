<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aksi massal di Produk dan Harga: publikasikan, jadikan draf, hapus. */
class ProductBulkActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role = 'store-admin'): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function product(string $name, bool $published = false, bool $variant = true): Product
    {
        $category = ProductCategory::first() ?? ProductCategory::create(['name_id' => 'Kue', 'name_en' => 'Cake', 'slug' => 'kue']);
        $product = Product::create(['name' => $name, 'slug' => str($name)->slug(), 'is_published' => $published, 'product_category_id' => $category->id]);
        if ($variant) {
            $product->variants()->create(['sku' => strtoupper(str($name)->slug()), 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        }

        return $product;
    }

    private function bulk(string $action, array $products, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->staff())->postJson('/api/v1/admin/products/bulk', ['action' => $action, 'ids' => array_map(fn (Product $p) => $p->public_id, $products)]);
    }

    public function test_publish_and_draft_many_products_and_report_the_ones_that_cannot_publish(): void
    {
        $ok = $this->product('Nastar');
        $noVariant = $this->product('Kosong', variant: false);

        $this->bulk('publish', [$ok, $noVariant])->assertOk()
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.failed.0.name', 'Kosong');
        $this->assertTrue($ok->refresh()->is_published);
        $this->assertFalse($noVariant->refresh()->is_published);

        $this->bulk('draft', [$ok])->assertOk()->assertJsonPath('data.done', 1);
        $this->assertFalse($ok->refresh()->is_published);
    }

    public function test_products_already_in_the_target_status_are_skipped(): void
    {
        $live = $this->product('Nastar', true);
        $draft = $this->product('Putri Salju');

        $this->bulk('publish', [$live, $draft])->assertOk()->assertJsonPath('data.done', 1)->assertJsonPath('data.failed', []);
        $this->bulk('draft', [$this->product('Kastengel')])->assertOk()->assertJsonPath('data.done', 0)->assertJsonPath('data.failed', []);
        $this->assertSame(1, AuditLog::where('action', 'product.updated')->count());
    }

    public function test_delete_soft_deletes_products_and_is_audited(): void
    {
        $a = $this->product('Nastar', true);
        $b = $this->product('Putri Salju');

        $this->bulk('delete', [$a, $b])->assertOk()->assertJsonPath('data.done', 2);

        $this->assertSame(0, Product::count());
        $this->assertSame(2, Product::onlyTrashed()->count());
        $this->assertSame(2, AuditLog::where('action', 'product.deleted')->count());
    }

    public function test_delete_is_refused_while_an_order_is_running_or_stock_is_reserved(): void
    {
        $held = $this->product('Nastar');
        $warehouse = Warehouse::firstOrCreate(['code' => 'MLG'], ['name' => 'Gudang Malang', 'city' => 'Malang', 'is_active' => true]);
        InventoryStock::create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $held->variants()->first()->id, 'quantity' => 5, 'reserved' => 2]);
        $free = $this->product('Putri Salju');

        $this->bulk('delete', [$held, $free])->assertOk()
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.failed.0.name', 'Nastar');

        $this->assertNotNull($held->fresh());
        $this->assertNull(Product::find($free->id));
    }

    public function test_only_product_managers_with_valid_input_can_use_it(): void
    {
        $product = $this->product('Nastar');

        $this->bulk('delete', [$product], $this->staff('customer-service'))->assertForbidden();
        $this->assertNotNull($product->fresh());

        $this->actingAs($this->staff())->postJson('/api/v1/admin/products/bulk', ['action' => 'nuke', 'ids' => [$product->public_id]])->assertUnprocessable();
        $this->actingAs($this->staff())->postJson('/api/v1/admin/products/bulk', ['action' => 'delete', 'ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/admin/products/bulk', ['action' => 'delete', 'ids' => ['tidak-ada']])->assertOk()->assertJsonPath('data.done', 0);
    }

    public function test_guests_are_rejected(): void
    {
        $this->postJson('/api/v1/admin/products/bulk', ['action' => 'delete', 'ids' => ['x']])->assertUnauthorized();
    }
}
