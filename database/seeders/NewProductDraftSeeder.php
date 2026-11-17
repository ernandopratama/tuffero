<?php

namespace Database\Seeders;

use App\Models\ProductVariant;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Draf produk yang belum masuk data (rapat 14 Nov 2026, D-117): Bola Susu dan Bites Fudgy. Hanya nama dan SKU yang pasti.
 * Harga, berat, kategori, foto, dan stok belum ada, jadi Admin Toko melengkapinya di dashboard Produk.
 * Tidak dipanggil DatabaseSeeder. Jalankan dengan php artisan db:seed --class=NewProductDraftSeeder.
 * SKU yang sudah ada dilewati, jadi perubahan dari dashboard tidak tertimpa.
 *
 * Pengaman: produk tidak terbit dan varian dibuat TIDAK AKTIF dengan harga 0. Produk tidak bisa dipublikasikan sebelum Admin Toko
 * mengisi harga dan mengaktifkan varian, jadi harga 0 tidak bisa tampil di toko. Berat 500 gram hanya pengisi (batas basis data > 0).
 */
class NewProductDraftSeeder extends Seeder
{
    /** @var list<array{sku: string, name: string}> */
    private const PRODUCTS = [
        ['sku' => 'BOL-SUS-01', 'name' => 'Bola Susu'],
        ['sku' => 'BIT-FUD-01', 'name' => 'Bites Fudgy'],
    ];

    public function run(ProductService $products): void
    {
        $imported = 0;

        foreach (self::PRODUCTS as $row) {
            if (ProductVariant::withTrashed()->where('sku', $row['sku'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($products, $row) {
                $product = $products->createProduct(['name' => $row['name']], null);
                $products->createVariant($product, ['sku' => $row['sku'], 'name' => 'Standar', 'price' => 0, 'weight_gram' => 500, 'is_active' => false], null);
            });

            $imported++;
        }

        $this->command?->info("{$imported} produk diimpor sebagai draf, ".(count(self::PRODUCTS) - $imported).' dilewati. Lengkapi harga, berat, kategori, dan foto di dashboard Produk.');
    }
}
