<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sesi dan perangkat milik akun staf (D-089): daftar, keluar dari satu perangkat, keluar dari semua perangkat lain. */
class ProfileSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Tes memakai driver array; sesi diuji lewat tabel sessions, jadi driver database dinyalakan untuk layanan ini.
        config(['session.driver' => 'database']);
    }

    private function staff(array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole('marketing');

        return $user;
    }

    private function addSession(User $user, string $id, string $agent = self::CHROME_WINDOWS, int $ago = 60): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.7', 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->subSeconds($ago)->getTimestamp()]);
    }

    public function test_lists_only_own_sessions_with_device_names_and_no_raw_session_id(): void
    {
        $user = $this->staff();
        $this->addSession($user, 'sesi-laptop-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::CHROME_WINDOWS, 120);
        $this->addSession($user, 'sesi-ponsel-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', self::SAFARI_IPHONE, 30);
        $this->addSession($this->staff(), 'sesi-orang-lain-cccccccccccccccccccccccccccccccc');

        $response = $this->actingAs($user)->getJson('/api/v1/admin/profile/sessions')->assertOk()->assertJsonPath('data.supported', true);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame(['Safari', 'iOS', true], [$items[0]['browser'], $items[0]['os'], $items[0]['mobile']], 'yang terbaru di atas');
        $this->assertSame(['Chrome', 'Windows', false], [$items[1]['browser'], $items[1]['os'], $items[1]['mobile']]);
        $this->assertStringNotContainsString('sesi-laptop', $response->getContent(), 'ID sesi asli tidak dikirim');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $items[0]['token']);
    }

    public function test_unsupported_driver_reports_so_and_lists_nothing(): void
    {
        config(['session.driver' => 'array']);

        $this->actingAs($this->staff())->getJson('/api/v1/admin/profile/sessions')->assertOk()->assertJsonPath('data.supported', false)->assertJsonCount(0, 'data.items');
    }

    public function test_revoke_one_other_device_is_audited(): void
    {
        $user = $this->staff();
        $this->addSession($user, 'sesi-laptop-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->addSession($user, 'sesi-ponsel-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->actingAs($user);

        $token = collect($this->getJson('/api/v1/admin/profile/sessions')->json('data.items'))->first()['token'];

        $this->deleteJson("/api/v1/admin/profile/sessions/{$token}")->assertOk();
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->where('id', 'like', 'sesi-%')->count());
        $this->assertSame(1, AuditLog::where('action', 'profile.session_revoked')->count());
        $this->deleteJson("/api/v1/admin/profile/sessions/{$token}")->assertNotFound();
    }

    public function test_cannot_revoke_someone_elses_session_or_a_made_up_token(): void
    {
        $user = $this->staff();
        $other = $this->staff();
        $this->addSession($other, 'sesi-orang-lain-cccccccccccccccccccccccccccccccc');
        $this->actingAs($user);

        $otherToken = substr(hash_hmac('sha256', 'sesi-orang-lain-cccccccccccccccccccccccccccccccc', (string) config('app.key')), 0, 32);

        $this->deleteJson("/api/v1/admin/profile/sessions/{$otherToken}")->assertNotFound();
        $this->deleteJson('/api/v1/admin/profile/sessions/'.str_repeat('a', 32))->assertNotFound();
        $this->deleteJson('/api/v1/admin/profile/sessions/bukan-token')->assertClientError();
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_revoke_all_other_devices_leaves_other_accounts_untouched(): void
    {
        $user = $this->staff();
        $other = $this->staff();
        $this->addSession($user, 'sesi-laptop-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->addSession($user, 'sesi-ponsel-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->addSession($other, 'sesi-orang-lain-cccccccccccccccccccccccccccccccc');

        $this->actingAs($user)->deleteJson('/api/v1/admin/profile/sessions')->assertOk()->assertJsonPath('data.revoked', 2);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->where('id', 'like', 'sesi-%')->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->where('id', 'like', 'sesi-%')->count());
        $this->assertSame(1, AuditLog::where('action', 'profile.session_revoked')->count());
    }

    public function test_requires_login_staff_role_and_enrolled_mfa_for_changes(): void
    {
        $this->getJson('/api/v1/admin/profile/sessions')->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/profile/sessions')->assertForbidden();
        $this->deleteJson('/api/v1/admin/profile/sessions')->assertForbidden();

        $this->actingAs($this->staff(['mfa_totp_confirmed_at' => null]));
        $this->deleteJson('/api/v1/admin/profile/sessions')->assertForbidden();
    }
}
