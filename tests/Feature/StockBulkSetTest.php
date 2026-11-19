<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Set stok massal di halaman Stok: satu jumlah untuk banyak varian, selisih dicatat sebagai penyesuaian. */
class StockBulkSetTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'MLG')->firstOrFail();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function variant(string $sku, int $quantity, int $reserved = 0): ProductVariant
    {
        $product = Product::create(['name' => "Produk {$sku}", 'slug' => strtolower($sku)]);
        $variant = $product->variants()->create(['sku' => $sku, 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'reserved' => $reserved]);

        return $variant;
    }

    private function bulk(array $variants, int $quantity, string $note = 'Hitung ulang gudang', ?User $user = null, array $extra = [])
    {
        return $this->actingAs($user ?? $this->staff('store-admin'))->postJson('/api/v1/admin/stock/bulk-set', [
            'warehouse_id' => $this->warehouse->public_id,
            'variant_ids' => array_map(fn (ProductVariant $v) => $v->public_id, $variants),
            'quantity' => $quantity,
            'note' => $note,
            ...$extra,
        ]);
    }

    private function quantity(ProductVariant $variant): int
    {
        return InventoryStock::where('product_variant_id', $variant->id)->value('quantity');
    }

    public function test_sets_the_same_quantity_for_many_variants_and_records_each_change(): void
    {
        [$a, $b, $same, $new] = [$this->variant('A-1', 10), $this->variant('B-1', 0), $this->variant('C-1', 50), $this->variant('D-1', 3)];
        InventoryStock::where('product_variant_id', $new->id)->delete();

        $this->bulk([$a, $b, $same, $new], 50)->assertOk()
            ->assertJsonPath('data.done', 3)
            ->assertJsonPath('data.unchanged', 1)
            ->assertJsonPath('data.failed', []);

        $this->assertSame([50, 50, 50, 50], [$this->quantity($a), $this->quantity($b), $this->quantity($same), $this->quantity($new)]);
        $this->assertSame(3, StockMovement::where('type', 'adjustment')->count());
        $this->assertSame([40, 50], StockMovement::whereIn('inventory_stock_id', InventoryStock::whereIn('product_variant_id', [$a->id, $b->id])->pluck('id'))->orderBy('quantity_change')->pluck('quantity_change')->all());
        $this->assertSame(3, AuditLog::where('action', 'stock.adjusted')->count());
    }

    public function test_a_quantity_below_the_reserved_stock_fails_only_that_variant(): void
    {
        $held = $this->variant('HELD-1', 10, reserved: 6);
        $free = $this->variant('FREE-1', 10);

        $this->bulk([$held, $free], 4)->assertOk()
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.failed.0.sku', 'HELD-1');

        $this->assertSame(10, $this->quantity($held));
        $this->assertSame(4, $this->quantity($free));
    }

    public function test_validation_and_permissions(): void
    {
        $variant = $this->variant('A-1', 10);

        $this->bulk([$variant], -1)->assertUnprocessable();
        $this->bulk([$variant], 5, note: '')->assertUnprocessable();
        $this->bulk([], 5)->assertUnprocessable();
        $this->bulk([$variant], 5, extra: ['warehouse_id' => 'bukan-ulid'])->assertUnprocessable();
        $this->bulk([$variant], 5, user: $this->staff('customer-service'))->assertForbidden();
        $this->assertSame(10, $this->quantity($variant));
    }

    public function test_guests_are_rejected(): void
    {
        $this->postJson('/api/v1/admin/stock/bulk-set', [])->assertUnauthorized();
    }
}
