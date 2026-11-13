<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Urutan foto produk dan foto utama (D-091): foto pertama menjadi foto utama. */
class ProductImageOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role = 'store-admin', array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{Product, list<int>} */
    private function productWithImages(int $count = 3): array
    {
        $slug = 'nastar-'.uniqid();
        $product = Product::create(['name' => 'Nastar', 'slug' => $slug]);
        $ids = [];
        foreach (range(1, $count) as $n) {
            $ids[] = $product->images()->create(['path' => "products/{$product->id}/{$n}.webp", 'alt' => "Foto {$n}", 'sort_order' => $n])->id;
        }

        return [$product, $ids];
    }

    private function order(Product $product, array $ids)
    {
        return $this->putJson("/api/v1/admin/products/{$product->id}/images/order", ['ids' => $ids]);
    }

    public function test_reordering_changes_the_main_photo_and_is_audited(): void
    {
        [$product, $ids] = $this->productWithImages();
        $this->actingAs($this->staff());

        $response = $this->order($product, [$ids[2], $ids[0], $ids[1]])->assertOk();

        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_column($response->json('data.images'), 'id'));
        $this->assertSame($ids[2], $product->fresh()->images()->first()->id, 'foto pertama menjadi foto utama');
        $log = AuditLog::where('action', 'product.images_reordered')->firstOrFail();
        $this->assertSame($ids[2], $log->changes['main_image_id']);
    }

    public function test_the_storefront_catalog_follows_the_new_order(): void
    {
        [$product, $ids] = $this->productWithImages(2);
        $product->forceFill(['is_published' => true])->save();
        $this->actingAs($this->staff());

        $this->order($product, [$ids[1], $ids[0]])->assertOk();

        $this->assertSame($ids[1], $product->fresh()->images->first()->id);
    }

    /** @return array<string, array{callable}> */
    public static function invalidOrders(): array
    {
        return [
            'kurang satu' => [fn (array $ids) => [$ids[0], $ids[1]]],
            'lebih satu (foto produk lain)' => [fn (array $ids) => [...$ids, 999999]],
            'ganda' => [fn (array $ids) => [$ids[0], $ids[0], $ids[1]]],
            'foto tidak dikenal' => [fn (array $ids) => [$ids[0], $ids[1], 999999]],
            'kosong' => [fn () => []],
        ];
    }

    #[DataProvider('invalidOrders')]
    public function test_the_list_must_match_exactly_the_products_photos(callable $make): void
    {
        [$product, $ids] = $this->productWithImages();
        $this->actingAs($this->staff());

        $this->order($product, $make($ids))->assertUnprocessable();
        $this->assertSame($ids, $product->images()->pluck('id')->all(), 'urutan tidak berubah');
    }

    public function test_photos_of_another_product_cannot_be_reordered_through_this_one(): void
    {
        [$product] = $this->productWithImages(2);
        [, $otherIds] = $this->productWithImages(2);
        $this->actingAs($this->staff());

        $this->order($product, $otherIds)->assertUnprocessable();
    }

    public function test_only_product_managers_with_mfa_can_reorder(): void
    {
        [$product, $ids] = $this->productWithImages(2);
        $reversed = [$ids[1], $ids[0]];

        $this->order($product, $reversed)->assertUnauthorized();

        foreach (['owner', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role));
            $this->order($product, $reversed)->assertForbidden();
        }

        $this->actingAs($this->staff('store-admin', ['mfa_totp_confirmed_at' => null]));
        $this->order($product, $reversed)->assertForbidden();

        $this->assertSame($ids, $product->images()->pluck('id')->all());
    }
}
