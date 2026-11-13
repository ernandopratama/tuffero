<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LoginDevice;
use App\Models\LoginFailure;
use App\Models\User;
use App\Services\SecurityHealthService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array<string, mixed>> */
    private function checks(): array
    {
        return collect($this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/system-health')->assertOk()->json('data.security'))->keyBy('key')->all();
    }

    private function failures(int $count, string $ip = '203.0.113.7', string $email = 'korban@example.com'): void
    {
        foreach (range(1, $count) as $_) {
            LoginFailure::create(['email_hash' => hash('sha256', $email), 'ip_address' => $ip]);
        }
    }

    public function test_a_wrong_password_is_recorded_as_a_hash_never_as_text(): void
    {
        $this->staff('owner', ['email' => 'Pemilik@Tuffero.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'Pemilik@Tuffero.com', 'password' => 'salah-sekali-123'])->assertUnauthorized();

        $row = LoginFailure::firstOrFail();
        $this->assertSame(hash('sha256', 'pemilik@tuffero.com'), $row->email_hash);
        $this->assertStringNotContainsString('tuffero.com', json_encode($row->toArray()));
        $this->assertStringNotContainsString('salah-sekali-123', json_encode($row->toArray()));
    }

    public function test_a_successful_login_is_not_recorded_as_a_failure(): void
    {
        $this->staff('owner', ['email' => 'pemilik@tuffero.com', 'password' => 'Rahasia-Panjang-123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'pemilik@tuffero.com', 'password' => 'Rahasia-Panjang-123'])->assertOk();

        $this->assertSame(0, LoginFailure::count());
    }

    public function test_failed_logins_are_counted_per_hour_with_their_spread(): void
    {
        $this->assertSame('ok', $this->checks()['failed_logins']['status']);

        $this->failures(4);
        $this->assertSame('ok', $this->checks()['failed_logins']['status']);

        $this->failures(8, '203.0.113.8', 'lain@example.com');
        $check = $this->checks()['failed_logins'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('12 percobaan gagal dari 2 IP ke 2 akun', $check['detail']);

        $this->failures(40, '203.0.113.9');
        $this->assertSame('bad', $this->checks()['failed_logins']['status']);

        LoginFailure::query()->update(['created_at' => now()->subHours(3)]);
        $this->assertSame('ok', $this->checks()['failed_logins']['status']);
    }

    public function test_many_different_ips_are_called_out_and_no_address_or_account_is_exposed(): void
    {
        foreach (range(1, 6) as $i) {
            $this->failures(2, "198.51.100.$i", "akun$i@example.com");
        }

        $response = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/system-health')->assertOk();
        $detail = collect($response->json('data.security'))->firstWhere('key', 'failed_logins')['detail'];

        $this->assertStringContainsString('terdistribusi', $detail);
        $this->assertStringNotContainsString('198.51.100', $response->getContent());
        $this->assertStringNotContainsString('akun1@example.com', $response->getContent());
    }

    public function test_only_a_new_device_for_a_known_account_is_flagged(): void
    {
        $user = $this->staff('owner');
        LoginDevice::create(['user_id' => $user->id, 'fingerprint' => 'perangkat-pertama', 'browser' => 'Chrome', 'os' => 'Windows', 'ip_address' => '203.0.113.1', 'first_seen_at' => now()->subMinutes(10), 'last_seen_at' => now()]);
        $this->assertSame('ok', $this->checks()['new_devices']['status']);

        LoginDevice::create(['user_id' => $user->id, 'fingerprint' => 'perangkat-baru', 'browser' => 'Firefox', 'os' => 'Linux', 'ip_address' => '203.0.113.2', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $check = $this->checks()['new_devices'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('1 perangkat baru', $check['detail']);

        LoginDevice::query()->update(['first_seen_at' => now()->subDays(3)]);
        $this->assertSame('ok', $this->checks()['new_devices']['status']);
    }

    public function test_recovery_code_and_mfa_reset_are_flagged_but_email_codes_are_not(): void
    {
        $user = $this->staff('owner');
        AuditLog::record('mfa.email_code_used', $user, [], $user->id);
        $this->assertSame('ok', $this->checks()['mfa_events']['status']);

        AuditLog::record('mfa.recovery_code_used', $user, [], $user->id);
        AuditLog::record('mfa.reset', $user, [], $user->id);
        $check = $this->checks()['mfa_events'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('1 kode pemulihan dipakai, 1 MFA direset', $check['detail']);
    }

    public function test_staff_without_mfa_are_counted_but_customers_are_not(): void
    {
        User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $this->assertSame('ok', $this->checks()['staff_no_mfa']['status']);

        $this->staff('marketing', ['mfa_totp_confirmed_at' => null]);
        $this->assertStringContainsString('1 akun staf', $this->checks()['staff_no_mfa']['detail']);
    }

    public function test_dashboard_raises_a_security_alert_for_super_admin_only_and_not_for_missing_mfa(): void
    {
        $attention = fn (string $role) => collect($this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertArrayNotHasKey('security_alert', $attention('super-admin')->all());

        $this->failures(60);
        $item = $attention('super-admin')['security_alert'];
        $this->assertSame(1, $item['count']);
        $this->assertSame('bad', $item['tone']);
        $this->assertArrayNotHasKey('security_alert', $attention('owner')->all());
    }

    public function test_pruning_keeps_the_retention_window_constant_sensible(): void
    {
        $this->assertSame(14, LoginFailure::KEEP_DAYS);
        $this->assertSame(10, SecurityHealthService::FAILED_WARN);
        $this->assertSame(50, SecurityHealthService::FAILED_BAD);
    }
}
