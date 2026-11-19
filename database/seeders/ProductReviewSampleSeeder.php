<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductReview;
use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;

/**
 * Ulasan Produk contoh: dua ulasan (bintang 5 dan 4) untuk setiap produk tayang, maksimal enam produk. Tanpa pesanan (order_item_id
 * kosong), jadi tidak mengganggu aturan satu ulasan per barang pesanan. Nama penulis fiktif. Aman diulang: ulasan dikenali dari
 * produk dan nama penulisnya. Ditolak di production (ulasan palsu tidak boleh tampil ke pembeli sungguhan).
 * Jalankan dengan php artisan db:seed --class=ProductReviewSampleSeeder (setelah CatalogDraftSeeder).
 */
class ProductReviewSampleSeeder extends Seeder
{
    use SampleData;

    private const REVIEWS = [
        ['author_name' => 'Sari (contoh)', 'rating' => 5, 'body' => 'Contoh ulasan: rasanya enak dan kemasannya rapi. Akan pesan lagi.'],
        ['author_name' => 'Budi (contoh)', 'rating' => 4, 'body' => 'Contoh ulasan: pengiriman cepat, teksturnya pas.'],
    ];

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $products = Product::where('is_published', true)->orderBy('id')->limit(6)->get();

        if ($products->isEmpty()) {
            $this->command?->warn('Belum ada produk tayang; ulasan contoh dilewati (jalankan CatalogDraftSeeder dan terbitkan produk dulu).');

            return;
        }

        $made = 0;
        foreach ($products as $product) {
            foreach (self::REVIEWS as $review) {
                $row = ProductReview::firstOrCreate(['product_id' => $product->id, 'author_name' => $review['author_name']], $review + ['is_published' => true]);
                $made += $row->wasRecentlyCreated ? 1 : 0;
            }
        }

        $this->command?->info("{$made} ulasan produk contoh dibuat untuk {$products->count()} produk.");
    }
}
