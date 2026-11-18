<?php

namespace Database\Seeders;

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ImageService;
use App\Services\InventoryService;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Impor awal 29 SKU (data/catalog.json, dari resources/js/data/products.js) sebagai draf, masing-masing dengan stok awal 50.
 * Tidak dipanggil DatabaseSeeder. Jalankan dengan php artisan db:seed --class=CatalogDraftSeeder.
 * SKU yang sudah ada dilewati, jadi perubahan dari dashboard tidak tertimpa. Stok awal hanya diberikan pada SKU yang belum
 * punya baris stok di gudang Malang, jadi stok yang sudah diubah dari dashboard tidak ditimpa. Data contoh untuk lokal.
 */
class CatalogDraftSeeder extends Seeder
{
    private const STARTING_STOCK = 50;

    public function run(ProductService $products, InventoryService $inventory): void
    {
        $rows = json_decode(File::get(database_path('seeders/data/catalog.json')), true, flags: JSON_THROW_ON_ERROR);
        $imported = 0;

        foreach ($rows as $row) {
            if (ProductVariant::withTrashed()->where('sku', $row['sku'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($products, $row) {
                $product = $products->createProduct(['name' => $row['name']], null);

                // shortcut: satu SKU menjadi satu produk dengan varian "Standar"; digabung per keluarga setelah OQ-23 diputuskan.
                $products->createVariant($product, [
                    'sku' => $row['sku'],
                    'name' => 'Standar',
                    'price' => $row['price'],
                    'compare_at_price' => $row['compare_at_price'],
                    'weight_gram' => $row['weight_gram'],
                    'net_weight_gram' => $row['net_weight_gram'],
                    'shelf_life_days' => $row['shelf_life_days'],
                ], null);

                foreach ($row['photos'] as $order => $photo) {
                    // Path acak tanpa ID dan nama asli (D-142); foto sumber di public/ selalu .webp atau .jpg.
                    $path = ImageService::randomPath('products', strtolower(pathinfo($photo, PATHINFO_EXTENSION)));
                    Storage::disk('public')->put($path, File::get(public_path($photo)));
                    $product->images()->create(['path' => $path, 'alt' => $row['name'], 'sort_order' => $order]);
                }
            });

            $imported++;
        }

        $this->command?->info("{$imported} SKU diimpor sebagai draf, ".(count($rows) - $imported).' dilewati.');
        $this->seedStock($inventory, array_column($rows, 'sku'));
    }

    /** @param  list<string>  $skus */
    private function seedStock(InventoryService $inventory, array $skus): void
    {
        $warehouse = Warehouse::where('code', 'MLG')->first();
        $actor = User::role('super-admin')->orderBy('id')->first();
        if (! $warehouse || ! $actor) {
            $this->command?->warn('Stok awal dilewati: gudang MLG atau akun super-admin belum ada (jalankan WarehouseSeeder dan DemoAccountSeeder).');

            return;
        }

        $added = 0;
        foreach (ProductVariant::whereIn('sku', $skus)->get() as $variant) {
            if (InventoryStock::where(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id])->exists()) {
                continue;
            }
            $inventory->adjust($warehouse->id, $variant->id, 'restock', self::STARTING_STOCK, 'Stok awal seeder', $actor);
            $added++;
        }

        $this->command?->info('Stok awal '.self::STARTING_STOCK." ditambahkan pada {$added} SKU.");
    }
}
