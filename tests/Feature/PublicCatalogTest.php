<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\Warehouse;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, bool $published, array $variant = []): Product
    {
        $product = Product::create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_published' => $published]);
        $product->variants()->create(['sku' => str($name)->upper()->slug()->toString(), 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 100, ...$variant]);

        return $product;
    }

    public function test_only_published_products_with_active_variants_are_public(): void
    {
        $this->product('Tayang', true);
        $this->product('Draf', false);
        $this->product('Tanpa Varian Aktif', true, ['is_active' => false]);
        $this->product('Terhapus', true)->delete();

        $this->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', 'Tayang');
    }

    public function test_inactive_variant_of_published_product_is_hidden(): void
    {
        $product = $this->product('Nastar', true);
        $product->variants()->create(['sku' => 'NONAKTIF', 'name' => 'Lama', 'price' => 1, 'weight_gram' => 1, 'is_active' => false]);

        $this->getJson('/api/v1/catalog')
            ->assertJsonCount(1, 'data.products.0.variants')
            ->assertJsonMissing(['sku' => 'NONAKTIF']);
    }

    public function test_catalog_shows_in_stock_flag_badges_and_order_limit_without_stock_numbers(): void
    {
        $this->seed(WarehouseSeeder::class);
        $stocked = $this->product('Nastar', true, ['max_order_quantity' => 12]);
        $stocked->update(['badges' => ['Terlaris']]);
        $this->product('Kosong', true);
        InventoryStock::create(['warehouse_id' => Warehouse::firstOrFail()->id, 'product_variant_id' => $stocked->variants()->firstOrFail()->id, 'quantity' => 7]);

        $products = collect($this->getJson('/api/v1/catalog')->assertOk()->json('data.products'))->keyBy('name');

        $this->assertTrue($products['Nastar']['variants'][0]['in_stock']);
        $this->assertFalse($products['Kosong']['variants'][0]['in_stock']);
        $this->assertSame(12, $products['Nastar']['variants'][0]['max_per_order']);
        $this->assertSame(['Terlaris'], $products['Nastar']['badges']);
        $this->assertArrayNotHasKey('quantity', $products['Nastar']['variants'][0]);
    }

    public function test_response_contains_prices_but_no_stock_or_internal_fields(): void
    {
        $this->seed(WarehouseSeeder::class);
        $product = $this->product('Nastar', true, ['compare_at_price' => 60000, 'shelf_life_days' => 21]);
        InventoryStock::create(['warehouse_id' => Warehouse::firstOrFail()->id, 'product_variant_id' => $product->variants()->firstOrFail()->id, 'quantity' => 7]);

        $response = $this->getJson('/api/v1/catalog')
            ->assertJsonPath('data.products.0.variants.0.price', 50000)
            ->assertJsonPath('data.products.0.variants.0.compare_at_price', 60000)
            ->assertJsonPath('data.products.0.variants.0.shelf_life_days', 21)
            ->assertJsonMissingPath('data.products.0.id')
            ->assertJsonMissingPath('data.products.0.is_published')
            ->assertJsonMissingPath('data.products.0.variants.0.id')
            ->assertJsonMissingPath('data.products.0.variants.0.is_active');

        $this->assertStringNotContainsString('quantity', $response->getContent());
        $this->assertStringNotContainsString('reserved', $response->getContent());
    }

    public function test_catalog_is_rate_limited(): void
    {
        foreach (range(1, 120) as $request) {
            $this->getJson('/api/v1/catalog')->assertOk();
        }

        $this->getJson('/api/v1/catalog')->assertTooManyRequests();
    }
}
