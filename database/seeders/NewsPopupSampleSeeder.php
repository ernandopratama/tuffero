<?php

namespace Database\Seeders;

use App\Models\NewsItem;
use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;

/**
 * Berita Popup contoh (carousel popup di toko): tiga item tayang (Promo, Update, Berita) dan satu draf. Teks dua bahasa,
 * gambar acak dari public/images/Produk. Isi hanya contoh dan tidak menyebut harga atau klaim. Aman diulang: item dikenali dari judulnya.
 * Ditolak di production. Jalankan dengan php artisan db:seed --class=NewsPopupSampleSeeder.
 */
class NewsPopupSampleSeeder extends Seeder
{
    use SampleData;

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $rows = [
            ['kind' => 'promo', 'title' => 'Promo Natal segera hadir', 'title_en' => 'Christmas promo coming soon', 'body' => 'Contoh: pantau voucher dan hampers Natal di halaman promo kami.', 'body_en' => 'Sample: follow our Christmas vouchers and hampers on the promo page.', 'cta_label' => 'Lihat promo', 'cta_label_en' => 'See promos', 'cta_url' => '/promo', 'sort_order' => 1, 'is_published' => true],
            ['kind' => 'update', 'title' => 'Pengiriman kini lewat Biteship', 'title_en' => 'Shipping now via Biteship', 'body' => 'Contoh: pilih kurir dan layanan saat checkout, ongkir dihitung otomatis.', 'body_en' => 'Sample: choose a courier at checkout, shipping is calculated automatically.', 'cta_label' => null, 'cta_label_en' => null, 'cta_url' => null, 'sort_order' => 2, 'is_published' => true],
            ['kind' => 'news', 'title' => 'Kue kering baru bulan ini', 'title_en' => 'New cookies this month', 'body' => 'Contoh: varian baru sedang kami siapkan. Ikuti kabarnya di sini.', 'body_en' => 'Sample: new flavors are on the way. Stay tuned.', 'cta_label' => null, 'cta_label_en' => null, 'cta_url' => null, 'sort_order' => 3, 'is_published' => true],
            ['kind' => 'news', 'title' => 'Draf: libur akhir tahun', 'title_en' => 'Draft: year-end holiday', 'body' => 'Contoh draf yang belum tayang tentang jadwal libur akhir tahun.', 'body_en' => 'Sample draft about the year-end holiday schedule.', 'cta_label' => null, 'cta_label_en' => null, 'cta_url' => null, 'sort_order' => 4, 'is_published' => false],
        ];

        $made = 0;
        foreach ($rows as $row) {
            $item = NewsItem::firstOrCreate(['title' => $row['title']], $row);
            $made += $item->wasRecentlyCreated ? 1 : 0;

            // Item lama tanpa gambar ikut diberi gambar; gambar yang sudah ada (termasuk unggahan admin) tidak ditimpa.
            if (! $item->image_path && ($path = $this->sampleImage('news'))) {
                $item->forceFill(['image_path' => $path])->save();
            }
        }

        $this->command?->info("{$made} berita popup contoh dibuat.");
    }
}
