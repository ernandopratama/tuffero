<?php

namespace Database\Seeders;

use App\Models\ProductVariant;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Impor awal 29 SKU (data/catalog.json, dari resources/js/data/products.js) sebagai draf tanpa stok.
 * Tidak dipanggil DatabaseSeeder. Jalankan dengan php artisan db:seed --class=CatalogDraftSeeder.
 * SKU yang sudah ada dilewati, jadi perubahan dari dashboard tidak tertimpa.
 */
class CatalogDraftSeeder extends Seeder
{
    public function run(ProductService $products): void
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
                    $path = "products/{$product->id}/".basename($photo);
                    Storage::disk('public')->put($path, File::get(public_path($photo)));
                    $product->images()->create(['path' => $path, 'alt' => $row['name'], 'sort_order' => $order]);
                }
            });

            $imported++;
        }

        $this->command?->info("{$imported} SKU diimpor sebagai draf, ".(count($rows) - $imported).' dilewati.');
    }
}
