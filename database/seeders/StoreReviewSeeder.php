<?php

namespace Database\Seeders;

use App\Models\StoreReview;
use Illuminate\Database\Seeder;

class StoreReviewSeeder extends Seeder
{
    /**
     * Lima ulasan Google Maps yang sudah tampil di toko (diambil 2026-11-03, OQ-21). Rating per ulasan tidak tercatat, jadi dikosongkan.
     * Ulasan yang sudah ada tidak ditimpa.
     */
    public function run(): void
    {
        $reviews = json_decode(file_get_contents(__DIR__.'/data/store_reviews.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($reviews as $review) {
            StoreReview::firstOrCreate(['author_name' => $review['author_name']], [...$review, 'source' => 'google', 'is_published' => true]);
        }
    }
}
