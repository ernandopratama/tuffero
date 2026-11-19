<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\ProductVariant;
use App\Models\Voucher;
use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Kampanye contoh: flash sale yang sedang berjalan, promo musiman yang aktif, dan satu yang sudah berakhir. Kampanye Natal diberi
 * satu voucher, flash sale diberi satu diskon varian (bila SKU-nya ada; jalankan CatalogDraftSeeder dulu). Angka hanya contoh,
 * bukan keputusan bisnis. Aman diulang: kampanye dikenali dari judulnya. Ditolak di production.
 * Jalankan dengan php artisan db:seed --class=CampaignSampleSeeder.
 */
class CampaignSampleSeeder extends Seeder
{
    use SampleData;

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $flash = $this->campaign('Flash Sale Akhir Pekan', 'Contoh: potongan harga untuk nastar selama tiga hari.', now()->subHour(), now()->addDays(3), true, true);
        $natal = $this->campaign('Promo Natal 2026', 'Contoh: voucher khusus musim Natal untuk semua pelanggan.', now()->subDay(), now()->addDays(40), false, true);
        $this->campaign('Promo Lebaran 2026 (selesai)', 'Contoh kampanye yang sudah berakhir.', now()->subDays(60), now()->subDays(30), false, false);

        Voucher::firstOrCreate(['code' => 'NATAL10'], [
            'description' => 'Contoh: diskon 10% musim Natal', 'type' => 'percent', 'kind' => Voucher::KIND_ORDER, 'audience' => 'all', 'value' => 10,
            'min_subtotal' => 100000, 'max_discount' => 30000, 'quota' => 100, 'per_customer_limit' => 1, 'is_active' => true, 'is_listed' => true,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(40), 'campaign_id' => $natal->id,
        ]);

        $variant = ProductVariant::where('sku', 'NAS-TOP-01')->first();
        if (! $variant) {
            $this->command?->warn('Diskon flash sale dilewati: SKU NAS-TOP-01 belum ada (jalankan CatalogDraftSeeder dulu).');
        } elseif (! $variant->discounts()->where('campaign_id', $flash->id)->exists()) {
            $discount = $variant->discounts()->create(['audience' => 'all', 'type' => 'percent', 'value' => 20, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3), 'is_active' => true]);
            $discount->forceFill(['campaign_id' => $flash->id])->save();
        }

        $this->command?->info('Kampanye contoh siap: '.Campaign::count().' kampanye.');
    }

    private function campaign(string $title, string $description, Carbon $starts, Carbon $ends, bool $flash, bool $active): Campaign
    {
        $campaign = Campaign::where('title', $title)->first();

        if ($campaign) {
            return $campaign;
        }

        $campaign = new Campaign(['title' => $title, 'description' => $description, 'starts_at' => $starts, 'ends_at' => $ends, 'is_flash' => $flash, 'is_active' => $active]);
        $campaign->forceFill(['slug' => $this->uniqueSlug($title)])->save();

        return $campaign;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'promo';
        $slug = $base;

        for ($i = 2; Campaign::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
