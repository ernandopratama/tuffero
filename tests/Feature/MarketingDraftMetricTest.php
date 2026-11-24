<?php

namespace Tests\Feature;

use App\Models\MarketingDraft;
use App\Models\MarketingDraftMetric;
use App\Models\User;
use App\Services\MarketingDraftService;
use App\Services\MarketingMetricService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Analisis konten pribadi: metrik harian manual untuk draf yang sudah diposting. Izin, validasi, ID publik, ringkasan. */
class MarketingDraftMetricTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/marketing-drafts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function draft(string $status = MarketingDraft::POSTED, string $title = 'Nastar Natal'): MarketingDraft
    {
        $draft = app(MarketingDraftService::class)->create(['type' => 'video_script', 'title' => $title, 'body' => 'Isi'], null, MarketingDraft::MANUAL);
        $draft->forceFill(['status' => $status])->save();

        return $draft->refresh();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'platform' => 'tiktok', 'post_url' => 'https://www.tiktok.com/@tuffero/video/123', 'measured_on' => now()->subDay()->toDateString(),
            'views' => 1000, 'likes' => 100, 'shares' => 10, 'comments' => 20, 'saves' => 5, 'reach' => null, 'new_followers' => 3,
            ...$overrides,
        ];
    }

    public function test_marketing_can_add_update_and_delete_metrics(): void
    {
        $marketing = $this->staff('marketing');
        $draft = $this->draft();

        $id = $this->actingAs($marketing)->postJson(self::URL."/{$draft->public_id}/metrics", $this->payload())
            ->assertCreated()->assertJsonPath('data.items.0.views', 1000)->json('data.items.0.id');
        $this->assertSame(26, strlen($id));

        $this->actingAs($marketing)->patchJson(self::URL."/{$draft->public_id}/metrics/{$id}", $this->payload(['views' => 1500]))
            ->assertOk()->assertJsonPath('data.items.0.views', 1500);

        $this->actingAs($marketing)->deleteJson(self::URL."/{$draft->public_id}/metrics/{$id}")->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_draft.metric_added']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_draft.metric_deleted']);
    }

    public function test_only_manage_permission_can_write_but_all_admin_roles_can_read(): void
    {
        $draft = $this->draft();
        $owner = $this->staff('owner');
        $warehouse = $this->staff('warehouse');

        $this->actingAs($owner)->postJson(self::URL."/{$draft->public_id}/metrics", $this->payload())->assertForbidden();
        $this->actingAs($warehouse)->postJson(self::URL."/{$draft->public_id}/metrics", $this->payload())->assertForbidden();
        $this->actingAs($owner)->getJson(self::URL."/{$draft->public_id}/metrics")->assertOk();
        $this->actingAs($warehouse)->getJson('/api/v1/admin/marketing-metrics/top')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL."/{$draft->public_id}/metrics")->assertUnauthorized();
    }

    public function test_marketing_store_admin_and_super_admin_can_record_but_owner_customer_service_and_warehouse_cannot(): void
    {
        $url = self::URL."/{$this->draft()->public_id}/metrics";

        foreach (['marketing', 'store-admin', 'super-admin'] as $role) {
            $day = now('Asia/Jakarta')->subDays(array_search($role, ['marketing', 'store-admin', 'super-admin']) + 1)->toDateString();
            $this->actingAs($this->staff($role))->postJson($url, $this->payload(['measured_on' => $day]))->assertCreated();
        }
        foreach (['owner', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->postJson($url, $this->payload(['measured_on' => now('Asia/Jakarta')->subDays(9)->toDateString()]))->assertForbidden();
            // Melihat tetap boleh untuk semua role admin.
            $this->actingAs($this->staff($role))->getJson($url)->assertOk();
        }
        $this->assertSame(3, MarketingDraftMetric::count());
    }

    public function test_content_metrics_permission_is_only_in_the_agreed_roles(): void
    {
        $roles = Role::whereIn('name', ['marketing', 'store-admin', 'owner', 'customer-service', 'warehouse'])->with('permissions')->get();
        $holders = $roles->filter(fn ($role) => $role->hasPermissionTo('content_metrics.manage'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['marketing', 'store-admin'], $holders);
    }

    public function test_marketing_without_mfa_cannot_write(): void
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $user->assignRole('marketing');

        $this->actingAs($user)->postJson(self::URL."/{$this->draft()->public_id}/metrics", $this->payload())->assertForbidden();
        $this->assertSame(0, MarketingDraftMetric::count());
    }

    public function test_validation_rejects_bad_platform_url_numbers_and_dates(): void
    {
        $marketing = $this->staff('marketing');
        $url = self::URL."/{$this->draft()->public_id}/metrics";

        $this->actingAs($marketing)->postJson($url, $this->payload(['platform' => 'youtube']))->assertJsonValidationErrors('platform');
        $this->actingAs($marketing)->postJson($url, $this->payload(['post_url' => 'http://www.tiktok.com/@a/video/1']))->assertJsonValidationErrors('post_url');
        $this->actingAs($marketing)->postJson($url, $this->payload(['post_url' => 'https://evil.example/@a/video/1']))->assertJsonValidationErrors('post_url');
        $this->actingAs($marketing)->postJson($url, $this->payload(['post_url' => 'https://www.instagram.com/reel/abc']))->assertJsonValidationErrors('post_url');
        $this->actingAs($marketing)->postJson($url, $this->payload(['post_url' => 'javascript:alert(1)']))->assertJsonValidationErrors('post_url');
        $this->actingAs($marketing)->postJson($url, $this->payload(['views' => -1, 'likes' => 1.5, 'shares' => 'banyak', 'reach' => -3]))->assertJsonValidationErrors(['views', 'likes', 'shares', 'reach']);
        $this->actingAs($marketing)->postJson($url, $this->payload(['measured_on' => now()->addDay()->toDateString()]))->assertJsonValidationErrors('measured_on');

        $this->actingAs($marketing)->postJson($url, $this->payload())->assertCreated();
        $this->actingAs($marketing)->postJson($url, $this->payload())->assertJsonValidationErrors('measured_on');
        $this->actingAs($marketing)->postJson($url, $this->payload(['platform' => 'instagram', 'post_url' => 'https://www.instagram.com/reel/abc']))->assertCreated();
    }

    public function test_metrics_only_for_posted_drafts(): void
    {
        $marketing = $this->staff('marketing');

        $this->actingAs($marketing)->postJson(self::URL."/{$this->draft(MarketingDraft::APPROVED)->public_id}/metrics", $this->payload())->assertJsonValidationErrors('draft');
    }

    public function test_numeric_ids_and_metrics_from_another_draft_return_404(): void
    {
        $marketing = $this->staff('marketing');
        $first = $this->draft();
        $second = $this->draft(title: 'Lain');
        $id = $this->actingAs($marketing)->postJson(self::URL."/{$first->public_id}/metrics", $this->payload())->json('data.items.0.id');
        $metric = MarketingDraftMetric::where('public_id', $id)->firstOrFail();

        $this->actingAs($marketing)->getJson(self::URL."/{$first->id}/metrics")->assertNotFound();
        $this->actingAs($marketing)->patchJson(self::URL."/{$first->public_id}/metrics/{$metric->id}", $this->payload())->assertStatus(405);
        $this->actingAs($marketing)->patchJson(self::URL."/{$second->public_id}/metrics/{$id}", $this->payload(['views' => 1]))->assertNotFound();
        $this->actingAs($marketing)->deleteJson(self::URL."/{$second->public_id}/metrics/{$id}")->assertNotFound();
        $this->assertSame(1000, $metric->refresh()->views);
    }

    public function test_summary_shows_latest_totals_change_and_engagement(): void
    {
        $marketing = $this->staff('marketing');
        $draft = $this->draft();
        $url = self::URL."/{$draft->public_id}/metrics";
        $this->actingAs($marketing)->postJson($url, $this->payload(['measured_on' => now()->subDays(2)->toDateString()]))->assertCreated();
        $this->actingAs($marketing)->postJson($url, $this->payload(['views' => 2000, 'likes' => 150, 'reach' => 1800]))->assertCreated();

        $this->actingAs($marketing)->getJson($url)->assertOk()
            ->assertJsonPath('data.summary.tiktok.days', 2)
            ->assertJsonPath('data.summary.tiktok.totals.views', 2000)
            ->assertJsonPath('data.summary.tiktok.change.views', 1000)
            ->assertJsonPath('data.summary.tiktok.change.likes', 50)
            ->assertJsonPath('data.summary.tiktok.change.reach', null)
            ->assertJsonPath('data.summary.tiktok.engagement_rate', 9.25);
    }

    public function test_top_ranks_content_per_platform_by_latest_views(): void
    {
        $marketing = $this->staff('marketing');
        $low = $this->draft(title: 'Sedikit');
        $high = $this->draft(title: 'Banyak');
        $deleted = $this->draft(title: 'Dihapus');
        $this->actingAs($marketing)->postJson(self::URL."/{$low->public_id}/metrics", $this->payload(['views' => 9000, 'measured_on' => now()->subDays(3)->toDateString()]));
        $this->actingAs($marketing)->postJson(self::URL."/{$low->public_id}/metrics", $this->payload(['views' => 500]));
        $this->actingAs($marketing)->postJson(self::URL."/{$high->public_id}/metrics", $this->payload(['views' => 3000]));
        $this->actingAs($marketing)->postJson(self::URL."/{$deleted->public_id}/metrics", $this->payload(['views' => 99999]));
        $deleted->delete();

        $this->actingAs($marketing)->getJson('/api/v1/admin/marketing-metrics/top')->assertOk()
            ->assertJsonCount(2, 'data.platforms.tiktok')
            ->assertJsonPath('data.platforms.tiktok.0.title', 'Banyak')
            ->assertJsonPath('data.platforms.tiktok.1.views', 500)
            ->assertJsonPath('data.platforms.tiktok.0.draft_id', $high->public_id)
            ->assertJsonCount(0, 'data.platforms.instagram');
    }

    public function test_measured_on_uses_jakarta_today_and_large_followers_are_stored(): void
    {
        $marketing = $this->staff('marketing');
        $url = self::URL."/{$this->draft()->public_id}/metrics";

        // 20:00 UTC tanggal 23 = 03:00 WIB tanggal 24: tanggal 24 sudah hari ini bagi tim, tanggal 25 belum.
        $this->travelTo(now('UTC')->setDate(2026, 11, 23)->setTime(20, 0));
        $this->actingAs($marketing)->postJson($url, $this->payload(['measured_on' => '2026-11-25']))->assertJsonValidationErrors('measured_on');
        $this->actingAs($marketing)->postJson($url, $this->payload(['measured_on' => '2026-11-24', 'new_followers' => 5000000000]))
            ->assertCreated()->assertJsonPath('data.items.0.new_followers', 5000000000);
    }

    public function test_share_links_are_accepted_and_invalid_platform_gets_no_url_error(): void
    {
        $marketing = $this->staff('marketing');
        $url = self::URL."/{$this->draft()->public_id}/metrics";

        $this->actingAs($marketing)->postJson($url, $this->payload(['post_url' => 'https://vt.tiktok.com/ZSabc123/']))->assertCreated();
        $this->actingAs($marketing)->postJson($url, $this->payload(['platform' => 'instagram', 'post_url' => 'https://instagr.am/p/abc/']))->assertCreated();
        // Host berbagi hanya untuk platformnya sendiri.
        $this->actingAs($marketing)->postJson($url, $this->payload(['platform' => 'instagram', 'measured_on' => now()->subDays(2)->toDateString(), 'post_url' => 'https://vt.tiktok.com/ZSabc123/']))->assertJsonValidationErrors('post_url');
        // Platform tidak valid: hanya galat platform, tanpa pesan tautan yang menyebut platform.
        $this->actingAs($marketing)->postJson($url, $this->payload(['platform' => 'youtube']))->assertJsonValidationErrors('platform')->assertJsonMissingValidationErrors('post_url');
    }

    public function test_metric_of_another_draft_is_404_before_validation_and_duplicate_race_is_a_validation_error(): void
    {
        $marketing = $this->staff('marketing');
        $first = $this->draft();
        $second = $this->draft(title: 'Lain');
        $id = $this->actingAs($marketing)->postJson(self::URL."/{$first->public_id}/metrics", $this->payload())->json('data.items.0.id');

        // Data tidak valid sengaja dikirim: tetap 404, bukan galat validasi yang membocorkan bahwa ID itu ada.
        $this->actingAs($marketing)->patchJson(self::URL."/{$second->public_id}/metrics/{$id}", ['views' => -1])->assertNotFound();

        // Simulasi balapan: validasi unik dilewati, indeks unik yang menolak, dan hasilnya galat validasi (bukan 500).
        $this->expectException(ValidationException::class);
        app(MarketingMetricService::class)->create($first, $this->payload(), $marketing);
    }

    public function test_metrics_cannot_be_changed_once_draft_is_no_longer_posted_but_can_still_be_deleted(): void
    {
        $marketing = $this->staff('marketing');
        $draft = $this->draft();
        $url = self::URL."/{$draft->public_id}/metrics";
        $id = $this->actingAs($marketing)->postJson($url, $this->payload())->json('data.items.0.id');
        $draft->forceFill(['status' => MarketingDraft::APPROVED])->save();

        $this->actingAs($marketing)->postJson($url, $this->payload(['measured_on' => now()->subDays(3)->toDateString()]))->assertJsonValidationErrors('draft');
        $this->actingAs($marketing)->patchJson("{$url}/{$id}", $this->payload(['views' => 5]))->assertJsonValidationErrors('draft');
        $this->actingAs($marketing)->deleteJson("{$url}/{$id}")->assertOk()->assertJsonCount(0, 'data.items');
    }

    private function record(MarketingDraft $draft, string $platform, int $daysAgo, int $views): void
    {
        $metric = new MarketingDraftMetric(['platform' => $platform, 'post_url' => 'https://www.tiktok.com/@a/video/1', 'measured_on' => now('Asia/Jakarta')->subDays($daysAgo)->toDateString(), 'views' => $views, 'likes' => 10, 'shares' => 2, 'comments' => 3, 'saves' => 5]);
        $metric->marketing_draft_id = $draft->id;
        $metric->save();
    }

    public function test_overview_summarises_totals_ranking_levels_and_trend(): void
    {
        $normal = $this->draft(title: 'Normal');
        $star = $this->draft(title: 'Bintang');
        $low = $this->draft(title: 'Rendah');
        $this->record($normal, 'tiktok', 2, 900);
        $this->record($normal, 'tiktok', 0, 1000);
        $this->record($star, 'tiktok', 0, 5000);
        $this->record($low, 'tiktok', 0, 100);
        $this->record($this->draft(title: 'Insta'), 'instagram', 0, 700);

        $response = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/marketing-metrics/overview?days=7')->assertOk();

        $response->assertJsonPath('data.items.0.title', 'Bintang')
            ->assertJsonPath('data.items.0.level', 'outlier')
            ->assertJsonPath('data.totals.contents', 4)
            ->assertJsonPath('data.totals.views', 6800)
            ->assertJsonPath('data.platforms.tiktok.contents', 3)
            ->assertJsonPath('data.platforms.instagram.views', 700);
        $items = collect($response->json('data.items'));
        $this->assertSame('low', $items->firstWhere('title', 'Rendah')['level']);
        $this->assertSame('normal', $items->firstWhere('title', 'Normal')['level']);
        $this->assertSame(100, $items->firstWhere('title', 'Normal')['views_change']);
        $this->assertFalse($items->firstWhere('title', 'Normal')['stale']);
        $this->assertSame([900, 1000], $items->firstWhere('title', 'Normal')['spark']);
        // Instagram hanya 1 konten: tingkat tidak dihitung.
        $this->assertNull($items->firstWhere('title', 'Insta')['level']);
        // Tren: 8 titik (7 hari lalu sampai hari ini); hari tanpa catatan membawa angka terakhir.
        $trend = $response->json('data.trend');
        $this->assertCount(8, $trend);
        $this->assertSame(['tiktok' => 0, 'instagram' => 0], array_intersect_key($trend[4], ['tiktok' => 1, 'instagram' => 1]));
        $this->assertSame(900, $trend[5]['tiktok']);
        $this->assertSame(900, $trend[6]['tiktok']);
        $this->assertSame(['tiktok' => 6100, 'instagram' => 700], array_intersect_key($trend[7], ['tiktok' => 1, 'instagram' => 1]));
        $this->assertSame(now('Asia/Jakarta')->toDateString(), $trend[7]['date']);

        $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/marketing-metrics/overview?days=7&platform=instagram')->assertJsonCount(1, 'data.items');
    }

    public function test_overview_period_filter_deleted_drafts_and_validation(): void
    {
        $old = $this->draft(title: 'Lama');
        $gone = $this->draft(title: 'Dihapus');
        $this->record($old, 'tiktok', 20, 500);
        $this->record($gone, 'tiktok', 0, 900);
        $gone->delete();
        $owner = $this->staff('owner');

        $this->actingAs($owner)->getJson('/api/v1/admin/marketing-metrics/overview?days=7')->assertJsonCount(0, 'data.items');
        $this->actingAs($owner)->getJson('/api/v1/admin/marketing-metrics/overview?days=30')->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.title', 'Lama')->assertJsonPath('data.items.0.stale', true);
        $this->actingAs($owner)->getJson('/api/v1/admin/marketing-metrics/overview?days=5')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/v1/admin/marketing-metrics/overview?platform=youtube')->assertUnprocessable();
    }

    public function test_overview_lists_posted_drafts_without_recent_records_and_is_view_protected(): void
    {
        $missing = $this->draft(title: 'Belum dicatat');
        $missing->forceFill(['posted_at' => now()->subDays(5)])->save();
        $fresh = $this->draft(title: 'Sudah dicatat');
        $fresh->forceFill(['posted_at' => now()->subDays(5)])->save();
        $this->record($fresh, 'tiktok', 1, 300);
        $ancient = $this->draft(title: 'Terlalu lama');
        $ancient->forceFill(['posted_at' => now()->subDays(200)])->save();

        $response = $this->actingAs($this->staff('marketing'))->getJson('/api/v1/admin/marketing-metrics/overview')->assertOk();
        $this->assertSame(['Belum dicatat'], collect($response->json('data.needs_update'))->pluck('title')->all());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/marketing-metrics/overview')->assertUnauthorized();
    }
}
