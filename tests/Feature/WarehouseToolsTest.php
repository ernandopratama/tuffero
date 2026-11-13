<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Alat Gudang: kode pos asal dari data gudang, riwayat stok global dengan CSV, dan batas stok menipis per varian. */
class WarehouseToolsTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $this->variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        $this->warehouse = Warehouse::where('code', 'MLG')->firstOrFail();
        InventoryStock::create(['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $this->variant->id, 'quantity' => 10]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function adjust(int $change, string $note, ?User $actor = null): void
    {
        app(InventoryService::class)->adjust($this->warehouse->id, $this->variant->id, 'adjustment', $change, $note, $actor ?? $this->staff('warehouse'));
    }

    /** @return array<string, mixed> */
    private function dashboardKpi(string $role, string $key): array
    {
        $kpis = $this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.kpis');

        return collect($kpis)->firstWhere('key', $key);
    }

    // ---------- kode pos asal ----------

    public function test_origin_postal_code_prefers_the_active_warehouse_then_falls_back_to_env(): void
    {
        config(['services.biteship.origin_postal_code' => null]);
        $this->assertNull(Warehouse::originPostalCode());

        config(['services.biteship.origin_postal_code' => '11111']);
        $this->assertSame('11111', Warehouse::originPostalCode());

        $this->warehouse->update(['postal_code' => '65141']);
        $this->assertSame('65141', Warehouse::originPostalCode());

        $this->warehouse->update(['is_active' => false]);
        $this->assertSame('11111', Warehouse::originPostalCode(), 'Gudang nonaktif diabaikan.');
    }

    // ---------- riwayat stok global ----------

    public function test_history_lists_all_movements_and_filters(): void
    {
        $this->adjust(5, 'Terima barang');
        $this->adjust(-2, 'Rusak');
        $cs = $this->staff('customer-service');

        $all = $this->actingAs($cs)->getJson('/api/v1/admin/stock/history')->assertOk();
        $all->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.items.0.note', 'Rusak')
            ->assertJsonPath('data.items.0.sku', 'NAS-01')
            ->assertJsonPath('data.items.0.warehouse', $this->warehouse->name);

        $this->actingAs($cs)->getJson('/api/v1/admin/stock/history?search=zzz')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($cs)->getJson('/api/v1/admin/stock/history?search=nas-01')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->actingAs($cs)->getJson('/api/v1/admin/stock/history?type=reserve')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($cs)->getJson('/api/v1/admin/stock/history?from='.now()->addDay()->toDateString())->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($cs)->getJson('/api/v1/admin/stock/history?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->assertJsonPath('data.meta.total', 2);
    }

    public function test_history_validates_filters_and_requires_stock_view(): void
    {
        $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/stock/history?type=hack')->assertJsonValidationErrors('type');
        $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/stock/history?from=2026-02-02&to=2026-01-01')->assertJsonValidationErrors('to');

        $marketing = $this->staff('marketing');
        $this->actingAs($marketing)->getJson('/api/v1/admin/stock/history')->assertForbidden();
        $this->actingAs($marketing)->getJson('/api/v1/admin/stock/history/export')->assertForbidden();
    }

    public function test_csv_export_has_a_header_the_rows_and_neutralises_formulas(): void
    {
        $this->adjust(5, '=HYPERLINK("http://evil.example")');
        $this->adjust(-1, 'Biasa');

        $response = $this->actingAs($this->staff('warehouse'))->get('/api/v1/admin/stock/history/export')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Waktu,Gudang,SKU,Produk,Varian,Jenis', $csv);
        $this->assertStringContainsString('NAS-01', $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv, 'Sel berawalan = diberi apostrof.');
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
        $this->assertStringContainsString('Biasa', $csv);
    }

    // ---------- batas stok per varian ----------

    public function test_store_admin_can_set_and_clear_the_variant_threshold(): void
    {
        $admin = $this->staff('store-admin');
        $url = "/api/v1/admin/variants/{$this->variant->id}";
        $base = ['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500];

        $this->actingAs($admin)->patchJson($url, [...$base, 'low_stock_threshold' => 3])->assertOk();
        $this->assertSame(3, $this->variant->refresh()->low_stock_threshold);

        $this->actingAs($admin)->patchJson($url, [...$base, 'low_stock_threshold' => -1])->assertJsonValidationErrors('low_stock_threshold');
        $this->actingAs($admin)->patchJson($url, [...$base, 'low_stock_threshold' => 'abc'])->assertJsonValidationErrors('low_stock_threshold');

        $this->actingAs($admin)->patchJson($url, [...$base, 'low_stock_threshold' => null])->assertOk();
        $this->assertNull($this->variant->refresh()->low_stock_threshold);
    }

    public function test_stock_list_shows_the_effective_threshold(): void
    {
        $warehouse = $this->staff('warehouse');

        $this->actingAs($warehouse)->getJson('/api/v1/admin/stock')->assertOk()->assertJsonPath('data.items.0.low_stock', 10);

        $this->variant->update(['low_stock_threshold' => 3]);
        $this->actingAs($warehouse)->getJson('/api/v1/admin/stock')->assertOk()->assertJsonPath('data.items.0.low_stock', 3);
    }

    public function test_dashboard_uses_the_variant_threshold_over_the_global_one(): void
    {
        $this->adjust(-4, 'Rusak'); // tersedia 6
        $this->assertSame(1, $this->dashboardKpi('warehouse', 'low_stock')['value'], 'Batas global 10: 6 menipis.');

        $this->variant->update(['low_stock_threshold' => 3]);
        $this->assertSame(0, $this->dashboardKpi('warehouse', 'low_stock')['value'], 'Batas varian 3: 6 aman.');

        $this->variant->update(['low_stock_threshold' => 8]);
        $this->assertSame(1, $this->dashboardKpi('warehouse', 'low_stock')['value'], 'Batas varian 8: 6 menipis.');
    }

    public function test_low_stock_notification_follows_the_variant_threshold(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $this->variant->update(['low_stock_threshold' => 3]);

        $this->adjust(-4, 'Rusak'); // tersedia 6: di bawah batas global 10, tetapi bukan batas varian 3
        $this->assertCount(0, $storeAdmin->fresh()->notifications);

        $this->adjust(-4, 'Rusak lagi'); // tersedia 2: melewati batas varian 3
        $this->assertCount(1, $storeAdmin->fresh()->notifications);
    }

    public function test_global_setting_still_applies_when_the_variant_has_no_threshold(): void
    {
        Setting::where('key', 'inventory.low_stock_threshold')->update(['value' => '5']);
        $this->adjust(-4, 'Rusak'); // tersedia 6
        $this->assertSame(0, $this->dashboardKpi('warehouse', 'low_stock')['value']);

        $this->adjust(-2, 'Rusak'); // tersedia 4
        $this->assertSame(1, $this->dashboardKpi('warehouse', 'low_stock')['value']);
    }
}
