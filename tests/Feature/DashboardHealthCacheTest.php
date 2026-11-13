<?php

namespace Tests\Feature;

use App\Models\LoginFailure;
use App\Models\User;
use App\Services\DashboardService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardHealthCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config(['dashboard.health_cache_seconds' => 60]);
        Cache::forget(DashboardService::HEALTH_CACHE_KEY);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @return Collection<string, array<string, mixed>> */
    private function attention(string $role = 'super-admin')
    {
        return collect($this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.attention'))->keyBy('key');
    }

    private function failures(int $count): void
    {
        foreach (range(1, $count) as $_) {
            LoginFailure::create(['email_hash' => hash('sha256', 'korban@example.com'), 'ip_address' => '203.0.113.7']);
        }
    }

    public function test_health_summary_is_reused_for_sixty_seconds_then_recomputed(): void
    {
        $this->assertArrayNotHasKey('security_alert', $this->attention()->all());
        $this->assertTrue(Cache::has(DashboardService::HEALTH_CACHE_KEY));

        $this->failures(60);
        $this->assertArrayNotHasKey('security_alert', $this->attention()->all(), 'masih memakai hasil cache');

        $this->travel(61)->seconds();
        $this->assertArrayHasKey('security_alert', $this->attention()->all());
    }

    public function test_cache_is_off_when_the_ttl_is_zero(): void
    {
        config(['dashboard.health_cache_seconds' => 0]);

        $this->attention();
        $this->assertFalse(Cache::has(DashboardService::HEALTH_CACHE_KEY));

        $this->failures(60);
        $this->assertArrayHasKey('security_alert', $this->attention()->all());
    }

    public function test_other_roles_never_compute_or_touch_the_health_cache(): void
    {
        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->attention($role);
        }

        $this->assertFalse(Cache::has(DashboardService::HEALTH_CACHE_KEY));
    }

    public function test_cached_summary_holds_only_counts_and_labels_no_secrets(): void
    {
        config(['services.biteship.webhook_secret' => 'rahasia-webhook-yang-panjang-sekali', 'services.midtrans.server_key' => 'SB-Mid-server-rahasia123']);

        $this->attention();
        $cached = Cache::get(DashboardService::HEALTH_CACHE_KEY);

        $this->assertSame(['system_bad', 'security', 'stuck', 'integrations'], array_keys($cached));
        $this->assertStringNotContainsString('rahasia', json_encode($cached));
    }

    public function test_checking_the_apis_or_marking_log_errors_handled_drops_the_cache(): void
    {
        Http::fake();
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->assertTrue(Cache::has(DashboardService::HEALTH_CACHE_KEY));
        $this->actingAs($admin)->postJson('/api/v1/admin/integrations/check')->assertOk();
        $this->assertFalse(Cache::has(DashboardService::HEALTH_CACHE_KEY));

        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->assertTrue(Cache::has(DashboardService::HEALTH_CACHE_KEY));
        $this->actingAs($admin)->postJson('/api/v1/admin/system-health/log-ack')->assertOk();
        $this->assertFalse(Cache::has(DashboardService::HEALTH_CACHE_KEY));
    }

    public function test_settings_page_data_is_never_served_from_the_dashboard_cache(): void
    {
        $admin = $this->staff('super-admin');
        $security = fn () => collect($this->actingAs($admin)->getJson('/api/v1/admin/system-health')->assertOk()->json('data.security'))->firstWhere('key', 'failed_logins')['status'];

        $this->attention();
        $this->assertSame('ok', $security());

        $this->failures(60);

        $this->assertSame('bad', $security());
    }
}
