<?php

namespace Database\Seeders;

use App\Models\PromoContent;
use App\Support\VideoLink;
use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;

/**
 * Konten Promo contoh: dua teks (satu tayang, satu draf) dan satu video YouTube (tayang). Banner tidak dibuat karena butuh
 * gambar unggahan. Teks hanya contoh dan tidak menjanjikan harga atau klaim; isi sungguhan ditulis dari dashboard.
 * Aman diulang: konten dikenali dari judulnya. Ditolak di production. Jalankan dengan php artisan db:seed --class=PromoContentSampleSeeder.
 */
class PromoContentSampleSeeder extends Seeder
{
    use SampleData;

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $video = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ';

        $rows = [
            ['type' => 'text', 'title' => 'Hampers Natal segera hadir', 'body' => 'Contoh teks promo: hampers berisi kue kering pilihan sedang disiapkan. Pantau halaman ini untuk kabar pemesanan.', 'is_published' => true, 'sort_order' => 1],
            ['type' => 'text', 'title' => 'Tips menyimpan kue kering (draf)', 'body' => 'Contoh teks yang belum tayang: simpan di toples kedap udara, jauhkan dari sinar matahari langsung.', 'is_published' => false, 'sort_order' => 2],
            ['type' => 'video', 'title' => 'Video contoh: proses membuat kue', 'body' => null, 'video_url' => $video, ...VideoLink::parse($video), 'is_published' => true, 'sort_order' => 3],
        ];

        $made = 0;
        foreach ($rows as $row) {
            $content = PromoContent::firstOrCreate(['title' => $row['title']], $row);
            $made += $content->wasRecentlyCreated ? 1 : 0;
        }

        $this->command?->info("{$made} konten promo contoh dibuat.");
    }
}
