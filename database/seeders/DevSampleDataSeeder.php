<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;

/**
 * Semua data contoh untuk lokal dan dev dalam satu perintah: pelanggan, pelanggan diblokir, voucher dan diskon (PromoSampleSeeder),
 * kampanye, konten promo, ulasan produk, berita popup, dan draf Kebijakan Privasi. Tidak dipanggil DatabaseSeeder dan ditolak di
 * production. Semua seeder di dalamnya aman diulang. Jalankan dengan php artisan db:seed --class=DevSampleDataSeeder.
 * Ulasan dan diskon butuh produk tayang: jalankan CatalogDraftSeeder lebih dulu bila katalog masih kosong.
 */
class DevSampleDataSeeder extends Seeder
{
    use SampleData;

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $this->call([
            CustomerSampleSeeder::class,
            CustomerBlockSampleSeeder::class,
            PromoSampleSeeder::class,
            CampaignSampleSeeder::class,
            PromoContentSampleSeeder::class,
            ProductReviewSampleSeeder::class,
            NewsPopupSampleSeeder::class,
            PrivacyDraftSeeder::class,
        ]);
    }
}
