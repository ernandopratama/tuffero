<?php

namespace Database\Seeders;

use App\Models\AccountAudienceSnapshot;
use App\Models\MarketingDraft;
use App\Models\MarketingDraftMetric;
use App\Models\SocialAccountMetric;
use App\Services\MarketingDraftService;
use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Queue;

/**
 * Data contoh Performa Konten (D-150) dan Performa Akun (D-151) untuk lokal dan dev. Semua ditandai [SEEDER]:
 * judul draf berawalan "[SEEDER]", catatan akun bercatatan "[SEEDER]", dan demografi bertag "[SEEDER]" di kolom data.
 * Tanggal relatif terhadap hari ini (WIB) supaya grafik selalu terisi. Angka tetap (tanpa acak), jadi hasilnya sama setiap dijalankan.
 *
 * - Aman diulang: data bertanda [SEEDER] dihapus dulu lalu dibuat ulang. Catatan, draf, dan demografi asli tidak disentuh.
 * - Catatan akun sengaja bukan data tes (`is_test` mati) supaya tampil di angka, grafik, rangkuman, dan kartu Ringkasan.
 * - Ditolak di production. Tidak dipanggil DatabaseSeeder. Jalankan dengan php artisan db:seed --class=MetricsSampleSeeder,
 *   atau lewat DevSampleDataSeeder.
 */
class MetricsSampleSeeder extends Seeder
{
    use SampleData;

    public const TAG = '[SEEDER]';

    /** Konten tayang: judul, platform, dan views kumulatif dari catatan tertua ke terbaru (selang 2 hari). */
    private const CONTENT = [
        ['Nastar keju lumer', 'tiktok', [1200, 4800, 9800, 15400, 21000]],
        ['Kastengel renyah 3 detik', 'tiktok', [800, 2100, 3600, 4900]],
        ['Unboxing hampers lebaran', 'tiktok', [300, 650, 900]],
        ['Proses panggang di dapur Malang', 'tiktok', [90, 140]],
        ['Carousel resep putri salju', 'instagram', [500, 1400, 2600, 3300, 4100]],
        ['Reels packing pesanan', 'instagram', [250, 700, 1500]],
        ['Testimoni pelanggan Surabaya', 'instagram', [120, 260]],
    ];

    public function run(MarketingDraftService $drafts): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $this->content($drafts);
        $this->accounts();
        $this->command?->info('Data contoh '.self::TAG.' dibuat: '.MarketingDraftMetric::count().' catatan konten, '.SocialAccountMetric::where('note', self::TAG)->count().' catatan akun.');
    }

    private function day(int $back): string
    {
        return now('Asia/Jakarta')->subDays($back)->toDateString();
    }

    private function content(MarketingDraftService $drafts): void
    {
        // Hapus permanen draf seeder sebelumnya (catatan metriknya ikut terhapus lewat foreign key).
        MarketingDraft::withTrashed()->where('external_id', 'like', 'seed-metric:%')->get()->each(fn (MarketingDraft $draft) => $draft->forceDelete());

        // Membuat draf video biasanya mengantre storyboard AI; data contoh tidak boleh memicu panggilan AI.
        $queue = Queue::getFacadeRoot();
        Queue::fake();

        foreach (self::CONTENT as $i => [$title, $platform, $views]) {
            $draft = $drafts->create(
                ['type' => 'video_script', 'title' => self::TAG.' '.$title, 'body' => 'Isi contoh untuk mencoba Performa Konten.', 'platform' => $platform],
                null,
                MarketingDraft::MANUAL,
                'seed-metric:'.($i + 1),
            );
            $draft->forceFill(['status' => MarketingDraft::POSTED, 'posted_at' => now()->subDays(12)])->save();

            $count = count($views);
            foreach ($views as $k => $value) {
                $metric = new MarketingDraftMetric([
                    'platform' => $platform,
                    'post_url' => $platform === 'tiktok' ? 'https://www.tiktok.com/@tuffero/video/'.(1000 + $i) : 'https://www.instagram.com/p/seeder'.$i.'/',
                    'measured_on' => $this->day(($count - 1 - $k) * 2 + ($i % 2)),
                    'views' => $value, 'likes' => intdiv($value, 12), 'shares' => intdiv($value, 60), 'comments' => intdiv($value, 90), 'saves' => intdiv($value, 40),
                ]);
                $metric->marketing_draft_id = $draft->id;
                $metric->save();
            }
        }

        Queue::swap($queue);
    }

    private function accounts(): void
    {
        SocialAccountMetric::where('note', self::TAG)->delete();
        AccountAudienceSnapshot::query()->where('data->tag', self::TAG)->delete();

        $ig = 44000;
        $tt = 252000;
        for ($back = 24; $back >= 1; $back--) {
            // Naik pelan dengan sedikit variasi tetap per hari.
            $ig += 20 + ($back * 7) % 40;
            $tt += 250 + ($back * 53) % 450;
            if ($back % 5 === 0) {
                continue; // beberapa hari sengaja tidak dicatat, seperti kenyataan
            }
            $wave = 1 + (($back * 37) % 30) / 100;
            $this->record(['platform' => 'instagram', 'period_days' => 1, 'period_end' => $this->day($back), 'measured_on' => $this->day($back - 1),
                'followers_total' => $ig, 'followers_gained' => 40, 'followers_lost' => 12, 'views' => (int) (5200 * $wave), 'profile_visits' => (int) (260 * $wave),
                'accounts_reached' => (int) (3800 * $wave), 'accounts_engaged' => (int) (430 * $wave), 'interactions' => (int) (610 * $wave), 'link_taps' => (int) (22 * $wave)]);
            $this->record(['platform' => 'tiktok', 'period_days' => 1, 'period_end' => $this->day($back), 'measured_on' => $this->day($back - 1),
                'followers_total' => $tt, 'followers_gained' => 420, 'followers_lost' => 70, 'views' => (int) (82000 * $wave), 'profile_visits' => (int) (2600 * $wave),
                'likes' => (int) (6400 * $wave), 'comments' => (int) (260 * $wave), 'shares' => (int) (190 * $wave), 'total_viewers' => (int) (62000 * $wave), 'unique_viewers' => (int) (51000 * $wave),
                'watch_time_seconds' => (int) (430000 * $wave), 'avg_watch_seconds' => 7, 'account_likes_total' => 1800000 + $back * -300]);
        }

        // Catatan mingguan dan bulanan: angka unik tidak sama dengan jumlah harian.
        $this->record(['platform' => 'instagram', 'period_days' => 7, 'period_end' => $this->day(1), 'measured_on' => $this->day(0), 'followers_total' => $ig, 'followers_gained' => 280, 'followers_lost' => 90,
            'views' => 36500, 'profile_visits' => 1830, 'accounts_reached' => 17400, 'accounts_engaged' => 2300, 'interactions' => 4280, 'link_taps' => 154]);
        $this->record(['platform' => 'tiktok', 'period_days' => 7, 'period_end' => $this->day(1), 'measured_on' => $this->day(0), 'followers_total' => $tt, 'followers_gained' => 2900, 'followers_lost' => 480,
            'views' => 574000, 'profile_visits' => 18200, 'likes' => 44800, 'comments' => 1820, 'shares' => 1330, 'total_viewers' => 330000, 'unique_viewers' => 210000, 'watch_time_seconds' => 3010000, 'avg_watch_seconds' => 7, 'account_likes_total' => 1800000]);
        $this->record(['platform' => 'instagram', 'period_days' => 30, 'period_end' => $this->day(1), 'measured_on' => $this->day(0), 'followers_total' => $ig, 'followers_gained' => 1180, 'followers_lost' => 380,
            'views' => 151000, 'profile_visits' => 7600, 'accounts_reached' => 52000, 'accounts_engaged' => 7100, 'interactions' => 17800, 'link_taps' => 640]);

        $month = now('Asia/Jakarta')->startOfMonth()->toDateString();
        foreach ([
            'instagram' => ['age' => [['label' => '18-24', 'percent' => 38], ['label' => '25-34', 'percent' => 41], ['label' => '35-44', 'percent' => 14], ['label' => '45-54', 'percent' => 5]],
                'gender' => [['label' => 'Perempuan', 'percent' => 82], ['label' => 'Laki-laki', 'percent' => 18]],
                'locations' => [['label' => 'Jakarta', 'percent' => 24], ['label' => 'Surabaya', 'percent' => 12], ['label' => 'Malang', 'percent' => 9]]],
            'tiktok' => ['age' => [['label' => '13-17', 'percent' => 12], ['label' => '18-24', 'percent' => 46], ['label' => '25-34', 'percent' => 30], ['label' => '35-44', 'percent' => 9]],
                'gender' => [['label' => 'Perempuan', 'percent' => 76], ['label' => 'Laki-laki', 'percent' => 24]],
                'locations' => [['label' => 'Jakarta', 'percent' => 19], ['label' => 'Bandung', 'percent' => 11], ['label' => 'Surabaya', 'percent' => 10]]],
        ] as $platform => $data) {
            // Demografi asli untuk platform dan bulan yang sama tidak ditimpa: indeks unik per platform dan bulan.
            if (AccountAudienceSnapshot::where(['platform' => $platform, 'month' => $month, 'is_test' => false])->exists()) {
                continue;
            }
            AccountAudienceSnapshot::create(['platform' => $platform, 'month' => $month, 'is_test' => false, 'data' => [...$data, 'tag' => self::TAG]]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function record(array $attributes): void
    {
        $metric = new SocialAccountMetric([...$attributes, 'is_test' => false, 'note' => self::TAG]);
        $metric->save();
    }
}
