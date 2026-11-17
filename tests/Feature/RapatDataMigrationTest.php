<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Migrasi data hasil rapat 14 Nov 2026: batas stok per varian (D-120) dan nomor WhatsApp CS (D-128). */
class RapatDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(string $file): void
    {
        (require database_path("migrations/{$file}.php"))->up();
    }

    public function test_variant_stock_limits_are_cleared_to_follow_the_global_limit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $custom = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Hampers', 'price' => 50000, 'weight_gram' => 500, 'low_stock_threshold' => 3]);
        $default = $product->variants()->create(['sku' => 'NAS-02', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);

        $this->migrate('2026_11_16_000040_clear_variant_low_stock_limits');

        $this->assertNull($custom->fresh()->low_stock_threshold);
        $this->assertNull($default->fresh()->low_stock_threshold);
        $this->assertSame('30', Setting::where('key', 'inventory.low_stock_threshold')->value('value'));
    }

    public function test_only_the_old_whatsapp_number_is_replaced(): void
    {
        $this->seed(DatabaseSeeder::class);
        $setting = fn () => Setting::where('key', 'contact.whatsapp_number');

        $setting()->update(['value' => '+6281231481755']);
        $this->migrate('2026_11_16_000041_update_contact_whatsapp_number');
        $this->assertSame('+6281252507880', $setting()->value('value'));

        // Nomor yang sudah diubah staf ke nomor lain tidak ditimpa.
        $setting()->update(['value' => '+6281200000000']);
        $this->migrate('2026_11_16_000041_update_contact_whatsapp_number');
        $this->assertSame('+6281200000000', $setting()->value('value'));
    }
}
