<?php

namespace Tests\Feature;

use App\Models\AccountAudienceSnapshot;
use App\Models\MarketingDraft;
use App\Models\MarketingDraftMetric;
use App\Models\SocialAccountMetric;
use App\Services\MarketingDraftService;
use Database\Seeders\MetricsSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Seeder data contoh metrik: bertanda [SEEDER], aman diulang, tidak menyentuh data asli, ditolak di production. */
class MetricsSampleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_tagged_data_and_is_idempotent(): void
    {
        Queue::fake();
        $this->seed(MetricsSampleSeeder::class);
        $this->seed(MetricsSampleSeeder::class);

        $this->assertSame(7, MarketingDraft::where('title', 'like', '[SEEDER] %')->count());
        $this->assertSame(24, MarketingDraftMetric::count());
        $this->assertGreaterThan(0, SocialAccountMetric::count());
        $this->assertSame(0, SocialAccountMetric::where('note', '!=', '[SEEDER]')->count());
        $this->assertSame(0, SocialAccountMetric::where('is_test', true)->count());
        $this->assertSame(2, AccountAudienceSnapshot::where('data->tag', '[SEEDER]')->count());
    }

    public function test_real_records_are_kept(): void
    {
        Queue::fake();
        $real = new SocialAccountMetric(['platform' => 'instagram', 'period_days' => 1, 'period_end' => now('Asia/Jakarta')->subDays(40)->toDateString(), 'measured_on' => now('Asia/Jakarta')->subDays(40)->toDateString(), 'followers_total' => 40000, 'note' => 'asli']);
        $real->save();

        $this->seed(MetricsSampleSeeder::class);

        $this->assertTrue(SocialAccountMetric::where('note', 'asli')->exists());
    }

    public function test_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        // Dipanggil langsung: db:seed sendiri meminta konfirmasi di production.
        $this->app->make(MetricsSampleSeeder::class)->run($this->app->make(MarketingDraftService::class));

        $this->assertSame(0, SocialAccountMetric::count());
        $this->assertSame(0, MarketingDraft::count());
    }
}
