<?php

namespace Tests\Feature;

use App\Models\AccountAudienceSnapshot;
use App\Models\AuditLog;
use App\Models\SocialAccountMetric;
use App\Models\User;
use App\Services\SocialMetricService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Performa akun media sosial (D-151 tahap 1): izin, validasi per platform, tag tes, pertumbuhan followers, tren, rangkuman. */
class SocialMetricTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/social-metrics';

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

    private function day(int $back): string
    {
        return now('Asia/Jakarta')->subDays($back)->toDateString();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'platform' => 'instagram', 'period_days' => 1, 'period_end' => $this->day(1), 'measured_on' => $this->day(0), 'is_test' => false,
            'followers_total' => 44400, 'followers_gained' => 30, 'followers_lost' => 10, 'views' => 5000, 'profile_visits' => 200,
            'accounts_reached' => 3000, 'accounts_engaged' => 400, 'interactions' => 520, 'link_taps' => 20,
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function record(string $platform, int $back, int $followers, ?int $views = null, array $attributes = []): SocialAccountMetric
    {
        $metric = new SocialAccountMetric([
            'platform' => $platform, 'period_days' => 1, 'period_end' => $this->day($back), 'measured_on' => $this->day($back),
            'is_test' => false, 'followers_total' => $followers, 'views' => $views, ...$attributes,
        ]);
        $metric->save();

        return $metric;
    }

    public function test_only_manage_permission_can_write_but_all_admin_roles_can_read(): void
    {
        foreach (['owner', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->postJson(self::URL, $this->payload())->assertForbidden();
            $this->actingAs($this->staff($role))->getJson(self::URL.'/overview')->assertOk();
        }
        foreach (['marketing', 'store-admin', 'super-admin'] as $i => $role) {
            $this->actingAs($this->staff($role))->postJson(self::URL, $this->payload(['period_end' => $this->day($i + 1)]))->assertCreated();
        }
        $this->assertSame(3, SocialAccountMetric::count());

        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL.'/overview')->assertUnauthorized();
    }

    public function test_permission_is_only_in_the_agreed_roles(): void
    {
        $roles = Role::whereIn('name', ['marketing', 'store-admin', 'owner', 'customer-service', 'warehouse'])->with('permissions')->get();
        $holders = $roles->filter(fn ($role) => $role->hasPermissionTo('social_metrics.manage'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['marketing', 'store-admin'], $holders);
    }

    public function test_writing_without_mfa_is_refused(): void
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $user->assignRole('marketing');

        $this->actingAs($user)->postJson(self::URL, $this->payload())->assertForbidden();
        $this->assertSame(0, SocialAccountMetric::count());
    }

    public function test_marketing_can_add_update_and_delete_and_every_change_is_audited(): void
    {
        $user = $this->staff('marketing');
        $id = $this->actingAs($user)->postJson(self::URL, $this->payload())->assertCreated()->assertJsonPath('data.followers_total', 44400)->json('data.id');

        $this->actingAs($user)->patchJson(self::URL."/{$id}", $this->payload(['followers_total' => 44500, 'views' => null]))->assertOk()->assertJsonPath('data.followers_total', 44500)->assertJsonPath('data.views', null);
        $this->actingAs($user)->deleteJson(self::URL."/{$id}")->assertOk();

        $this->assertSame(0, SocialAccountMetric::count());
        $this->assertSame(['social_metric.added', 'social_metric.deleted', 'social_metric.updated'], AuditLog::pluck('action')->sort()->values()->all());
    }

    public function test_numeric_ids_return_404_and_unknown_ids_cannot_be_changed(): void
    {
        $metric = $this->record('instagram', 1, 44000);
        $user = $this->staff('marketing');

        // Angka bukan ID publik: rute tidak cocok (sama dengan metrik konten, dijawab 405), dan data tidak berubah.
        $this->actingAs($user)->patchJson(self::URL."/{$metric->id}", $this->payload())->assertStatus(405);
        $this->actingAs($user)->deleteJson(self::URL.'/01jzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
        $this->assertSame(1, SocialAccountMetric::count());
    }

    public function test_validation_rejects_bad_platform_period_dates_and_numbers(): void
    {
        $user = $this->staff('marketing');

        $this->actingAs($user)->postJson(self::URL, $this->payload(['platform' => 'youtube']))->assertUnprocessable()->assertJsonValidationErrors('platform');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['period_days' => 3]))->assertUnprocessable()->assertJsonValidationErrors('period_days');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['period_end' => now('Asia/Jakarta')->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('period_end');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['measured_on' => $this->day(5), 'period_end' => $this->day(1)]))->assertUnprocessable()->assertJsonValidationErrors('measured_on');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['followers_total' => null]))->assertUnprocessable()->assertJsonValidationErrors('followers_total');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['views' => -1]))->assertUnprocessable()->assertJsonValidationErrors('views');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['views' => 1.5]))->assertUnprocessable()->assertJsonValidationErrors('views');
        $this->assertSame(0, SocialAccountMetric::count());
    }

    public function test_numbers_of_the_other_platform_are_refused_and_empty_means_not_available(): void
    {
        $user = $this->staff('marketing');

        // Instagram tidak punya watch time; TikTok tidak punya link taps.
        $this->actingAs($user)->postJson(self::URL, $this->payload(['watch_time_seconds' => 10]))->assertUnprocessable()->assertJsonValidationErrors('watch_time_seconds');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['platform' => 'tiktok']))->assertUnprocessable()->assertJsonValidationErrors(['accounts_reached', 'link_taps']);

        $tiktok = ['platform' => 'tiktok', 'followers_total' => 258100, 'views' => 90000, 'likes' => 5000, 'watch_time_seconds' => 360000, 'avg_watch_seconds' => 7];
        $this->actingAs($user)->postJson(self::URL, $tiktok + ['period_days' => 1, 'period_end' => $this->day(1), 'measured_on' => $this->day(0), 'is_test' => false])->assertCreated()
            ->assertJsonPath('data.likes', 5000)->assertJsonPath('data.shares', null)->assertJsonMissingPath('data.link_taps');
        // Kosong disimpan sebagai null, bukan 0.
        $this->assertNull(SocialAccountMetric::first()->shares);
    }

    public function test_changing_platform_clears_numbers_of_the_old_one(): void
    {
        $user = $this->staff('marketing');
        $id = $this->actingAs($user)->postJson(self::URL, $this->payload())->json('data.id');

        $this->actingAs($user)->patchJson(self::URL."/{$id}", ['platform' => 'tiktok', 'period_days' => 1, 'period_end' => $this->day(1), 'measured_on' => $this->day(0), 'is_test' => false, 'followers_total' => 258100, 'likes' => 10])->assertOk();

        $metric = SocialAccountMetric::first();
        $this->assertNull($metric->link_taps);
        $this->assertNull($metric->accounts_reached);
        $this->assertSame(10, $metric->likes);
    }

    public function test_one_record_per_platform_period_and_end_but_test_records_may_coexist(): void
    {
        $user = $this->staff('marketing');
        $this->actingAs($user)->postJson(self::URL, $this->payload())->assertCreated();

        $this->actingAs($user)->postJson(self::URL, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('period_end');
        $this->actingAs($user)->postJson(self::URL, $this->payload(['period_days' => 7]))->assertCreated();
        $this->actingAs($user)->postJson(self::URL, $this->payload(['platform' => 'tiktok', 'accounts_reached' => null, 'accounts_engaged' => null, 'interactions' => null, 'link_taps' => null]))->assertCreated();
        $this->actingAs($user)->postJson(self::URL, $this->payload(['is_test' => true]))->assertCreated();
        $this->assertSame(4, SocialAccountMetric::count());
    }

    public function test_overview_computes_followers_growth_and_comparison_per_platform(): void
    {
        $this->record('instagram', 6, 44000, 1000, ['interactions' => 100, 'profile_visits' => 100, 'link_taps' => 10]);
        $this->record('instagram', 3, 44200, 2000, ['interactions' => 150, 'profile_visits' => 100, 'link_taps' => 10]);
        $this->record('instagram', 0, 44440, 3000, ['interactions' => 200, 'profile_visits' => 100, 'link_taps' => 10]);
        $this->record('tiktok', 5, 250000, 50000, ['likes' => 100, 'comments' => 10, 'shares' => 5]);
        $this->record('tiktok', 0, 258100, 60000, ['likes' => 200, 'comments' => 20, 'shares' => 10]);

        $response = $this->actingAs($this->staff('owner'))->getJson(self::URL.'/overview?days=7')->assertOk();

        $response->assertJsonPath('data.platforms.instagram.followers.current', 44440)
            ->assertJsonPath('data.platforms.instagram.followers.change', 440)
            ->assertJsonPath('data.platforms.instagram.followers.change_percent', 1)
            ->assertJsonPath('data.platforms.tiktok.followers.change', 8100)
            ->assertJsonPath('data.platforms.instagram.totals.views', 6000)
            ->assertJsonPath('data.platforms.instagram.totals.link_taps', 30)
            ->assertJsonPath('data.platforms.tiktok.totals.likes', 300)
            ->assertJsonPath('data.platforms.instagram.latest.followers_total', 44440);
        $texts = collect($response->json('data.insights'))->pluck('text')->implode(' | ');
        $this->assertStringContainsString('Followers Instagram naik 440', $texts);
        $this->assertStringContainsString('Followers TikTok naik 8.100', $texts);
        $this->assertStringContainsString('10 menekan tautan', str_replace('10,0', '10', $texts));
        $this->assertStringContainsString('tumbuh lebih cepat', $texts);
    }

    public function test_trend_has_one_point_per_day_carries_followers_and_only_fills_views_on_record_days(): void
    {
        $this->record('instagram', 4, 44000, 1000);
        $this->record('instagram', 1, 44100, 1500);

        $trend = $this->actingAs($this->staff('owner'))->getJson(self::URL.'/overview?days=7&platform=instagram')->json('data.trend');

        $this->assertCount(8, $trend);
        $this->assertSame($this->day(7), $trend[0]['date']);
        $this->assertSame($this->day(0), $trend[7]['date']);
        $this->assertArrayNotHasKey('instagram', $trend[2]['views']);
        $this->assertSame(44000, $trend[3]['followers']['instagram']);
        $this->assertSame(1000, $trend[3]['views']['instagram']);
        $this->assertSame(44000, $trend[5]['followers']['instagram']);
        $this->assertSame(44100, $trend[6]['followers']['instagram']);
        $this->assertSame(44100, $trend[7]['followers']['instagram']);
    }

    public function test_period_selects_which_records_feed_views_and_a_record_before_the_range_is_the_growth_baseline(): void
    {
        $this->record('instagram', 20, 43000, 100);
        $weekly = $this->record('instagram', 1, 44000, 7000);
        $weekly->forceFill(['period_days' => 7])->save();

        $daily = $this->actingAs($this->staff('owner'))->getJson(self::URL.'/overview?days=7&period=1')->json('data.platforms.instagram');
        $week = $this->actingAs($this->staff('owner'))->getJson(self::URL.'/overview?days=7&period=7')->json('data.platforms.instagram');

        $this->assertNull($daily['latest']);
        $this->assertSame(7000, $week['totals']['views']);
        // Titik awal 43.000 (sebelum rentang) menjadikan pertumbuhan terhitung walau hanya ada satu catatan dalam rentang.
        $this->assertSame(1000, $daily['followers']['change']);
    }

    public function test_test_records_are_excluded_unless_requested_and_can_be_deleted_together(): void
    {
        $this->record('instagram', 1, 44000, 1000);
        $this->record('instagram', 0, 99999, 9, ['is_test' => true]);
        $audience = AccountAudienceSnapshot::create(['platform' => 'instagram', 'month' => now('Asia/Jakarta')->startOfMonth()->toDateString(), 'is_test' => true, 'data' => ['age' => [['label' => '18-24', 'percent' => 50]]]]);
        $this->assertNotNull($audience->id);
        $user = $this->staff('marketing');

        $plain = $this->actingAs($user)->getJson(self::URL.'/overview?days=7')->assertOk();
        $plain->assertJsonPath('data.platforms.instagram.followers.current', 44000)->assertJsonPath('data.test_count', 2)->assertJsonPath('data.audience.instagram', null);
        $with = $this->actingAs($user)->getJson(self::URL.'/overview?days=7&include_test=1')->assertOk();
        $with->assertJsonPath('data.platforms.instagram.followers.current', 99999);
        $this->assertTrue(collect($with->json('data.records'))->contains('is_test', true));

        $this->actingAs($this->staff('owner'))->deleteJson(self::URL)->assertForbidden();
        $this->actingAs($user)->deleteJson(self::URL)->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSame([false], SocialAccountMetric::pluck('is_test')->all());
        $this->assertSame(0, AccountAudienceSnapshot::count());
        $this->assertTrue(AuditLog::where('action', 'social_metric.test_data_deleted')->exists());
    }

    public function test_stale_accounts_are_reminded_and_overview_validates_filters(): void
    {
        $this->record('instagram', 5, 44000, 1000);
        $owner = $this->staff('owner');

        $response = $this->actingAs($owner)->getJson(self::URL.'/overview?days=7')->assertOk();
        $this->assertTrue($response->json('data.platforms.instagram.stale'));
        $this->assertTrue($response->json('data.platforms.tiktok.stale'));
        $this->assertStringContainsString('belum punya catatan', collect($response->json('data.insights'))->pluck('text')->implode(' '));

        $this->actingAs($owner)->getJson(self::URL.'/overview?days=5')->assertUnprocessable();
        $this->actingAs($owner)->getJson(self::URL.'/overview?period=3')->assertUnprocessable();
        $this->actingAs($owner)->getJson(self::URL.'/overview?platform=youtube')->assertUnprocessable();
    }

    public function test_views_shift_needs_four_records_and_compares_halves(): void
    {
        $this->assertNull(SocialMetricService::viewsShift([1, 2, 3]));
        $this->assertSame(100.0, SocialMetricService::viewsShift([100, 100, 200, 200]));
        $this->assertSame(-50.0, SocialMetricService::viewsShift([200, null, 200, 100, 100]));
        $this->assertNull(SocialMetricService::viewsShift([0, 0, 5, 5]));
    }

    public function test_audience_snapshot_is_saved_replaced_validated_and_deleted(): void
    {
        $user = $this->staff('marketing');
        $body = ['platform' => 'instagram', 'month' => now('Asia/Jakarta')->format('Y-m'), 'is_test' => false,
            'age' => [['label' => '18-24', 'percent' => 60], ['label' => '25-34', 'percent' => 40]],
            'gender' => [['label' => 'Perempuan', 'percent' => 80]], 'locations' => []];

        $id = $this->actingAs($user)->putJson('/api/v1/admin/social-audience', $body)->assertCreated()->json('data.id');
        $this->actingAs($user)->putJson('/api/v1/admin/social-audience', [...$body, 'gender' => [['label' => 'Perempuan', 'percent' => 70]]])->assertCreated();
        $this->assertSame(1, AccountAudienceSnapshot::count());
        $this->assertEquals(70, AccountAudienceSnapshot::first()->data['gender'][0]['percent']);

        $overview = $this->actingAs($this->staff('owner'))->getJson(self::URL.'/overview?days=30')->json('data.audience.instagram');
        $this->assertSame('18-24', $overview['data']['age'][0]['label']);

        $this->actingAs($user)->putJson('/api/v1/admin/social-audience', [...$body, 'age' => [['label' => 'a', 'percent' => 70], ['label' => 'b', 'percent' => 60]]])->assertUnprocessable()->assertJsonValidationErrors('age');
        $this->actingAs($user)->putJson('/api/v1/admin/social-audience', [...$body, 'age' => [], 'gender' => [], 'locations' => []])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/admin/social-audience', [...$body, 'month' => now('Asia/Jakarta')->addMonth()->format('Y-m')])->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->actingAs($this->staff('owner'))->putJson('/api/v1/admin/social-audience', $body)->assertForbidden();

        $this->actingAs($user)->deleteJson("/api/v1/admin/social-audience/{$id}")->assertOk();
        $this->assertSame(0, AccountAudienceSnapshot::count());
    }
}
