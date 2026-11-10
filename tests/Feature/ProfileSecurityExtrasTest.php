<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LoginDevice;
use App\Models\User;
use App\Notifications\StaffEvent;
use App\Services\MfaService;
use App\Services\StaffNotifier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Profil staf (D-090): ganti authenticator sendiri, peringatan perangkat baru, riwayat aktivitas, dan preferensi notifikasi. */
class ProfileSecurityExtrasTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Kata-Sandi-Lama-1';

    private const OLD_SECRET = 'GEZDGNBVGY3TQOJQ';

    private const NEW_SECRET = 'MFRGGZDFMZTWQ2LK';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role = 'store-admin', array $attributes = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD, 'mfa_totp_confirmed_at' => now(), 'mfa_totp_secret' => self::OLD_SECRET, 'mfa_required' => true, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function code(string $secret, int $offset = 0): string
    {
        return app(MfaService::class)->totp($secret, (int) floor(now()->getTimestamp() / 30) + $offset);
    }

    // ---------- Ganti authenticator sendiri ----------

    public function test_replacing_the_authenticator_needs_password_and_the_current_factor(): void
    {
        $user = $this->staff();
        $this->actingAs($user);
        $url = '/api/v1/admin/profile/mfa/start';

        $this->postJson($url, ['current_password' => 'salah-total-1A', 'code' => $this->code(self::OLD_SECRET)])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson($url, ['current_password' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson($url, ['current_password' => self::PASSWORD, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson($url, ['current_password' => self::PASSWORD, 'code' => $this->code(self::OLD_SECRET)])
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_uri', 'qr_code']]);
        $this->assertSame(self::OLD_SECRET, $user->fresh()->mfa_totp_secret, 'authenticator lama utuh sampai dikonfirmasi');
    }

    public function test_a_recovery_code_can_prove_the_current_factor(): void
    {
        $user = $this->staff();
        $recovery = app(MfaService::class)->confirmEnrollment($user, $this->code(self::OLD_SECRET)) ?? [];
        $user->forceFill(['mfa_totp_secret' => self::OLD_SECRET])->save();
        $this->actingAs($user->fresh());

        $this->postJson('/api/v1/admin/profile/mfa/start', ['current_password' => self::PASSWORD, 'recovery_code' => 'salah-salah'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/profile/mfa/start', ['current_password' => self::PASSWORD, 'recovery_code' => $recovery[0] ?? 'x'])->assertOk();
    }

    public function test_confirming_with_the_new_authenticator_replaces_secret_and_recovery_codes(): void
    {
        $user = $this->staff();
        $mfa = app(MfaService::class);
        $oldCodes = $mfa->confirmEnrollment($user, $this->code(self::OLD_SECRET)) ?? [];
        $user->forceFill(['mfa_totp_secret' => self::OLD_SECRET])->save();
        $this->actingAs($user->fresh());

        $start = $this->postJson('/api/v1/admin/profile/mfa/start', ['current_password' => self::PASSWORD, 'code' => $this->code(self::OLD_SECRET, 1)])->assertOk();
        $newSecret = $start->json('data.secret');

        $this->postJson('/api/v1/admin/profile/mfa/confirm', ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(self::OLD_SECRET, $user->fresh()->mfa_totp_secret, 'kode salah tidak mengganti apa pun');

        $response = $this->postJson('/api/v1/admin/profile/mfa/confirm', ['code' => $this->code($newSecret)])->assertOk();

        $this->assertCount(8, $response->json('data.recovery_codes'));
        $this->assertSame($newSecret, $user->fresh()->mfa_totp_secret);
        $this->assertFalse($mfa->verifyRecoveryCode($user->fresh(), $oldCodes[0] ?? 'x'), 'kode pemulihan lama tidak berlaku lagi');
        $this->assertTrue($mfa->verifyRecoveryCode($user->fresh(), $response->json('data.recovery_codes.0')));
        $this->assertSame(1, AuditLog::where('action', 'mfa.replaced_self')->count());
    }

    public function test_confirm_without_starting_or_after_expiry_is_refused(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        $this->postJson('/api/v1/admin/profile/mfa/confirm', ['code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/admin/profile/mfa/start', ['current_password' => self::PASSWORD, 'code' => $this->code(self::OLD_SECRET)])->assertOk();
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/admin/profile/mfa/confirm', ['code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_replacing_requires_an_enrolled_staff_account(): void
    {
        $this->actingAs(User::factory()->create(['password' => self::PASSWORD]));
        $this->postJson('/api/v1/admin/profile/mfa/start', ['current_password' => self::PASSWORD, 'code' => '123456'])->assertForbidden();

        $this->actingAs($this->staff('store-admin', ['mfa_totp_confirmed_at' => null]));
        $this->postJson('/api/v1/admin/profile/mfa/start', ['current_password' => self::PASSWORD, 'code' => '123456'])->assertForbidden();
    }

    // ---------- Peringatan perangkat baru ----------

    private function loginFrom(User $user, string $agent, string $ip = '203.0.113.9'): void
    {
        $this->flushSession();
        $this->withHeaders(['User-Agent' => $agent])->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_first_login_is_silent_and_a_new_device_raises_one_alert(): void
    {
        $user = $this->staff('marketing', ['mfa_required' => false]);
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

        $this->loginFrom($user, $chrome);
        $this->assertSame(0, $user->notifications()->count(), 'masuk pertama tidak diperingatkan');
        $this->assertSame(1, LoginDevice::where('user_id', $user->id)->count());

        $this->loginFrom($user, $chrome, '198.51.100.5');
        $this->assertSame(0, $user->notifications()->count(), 'perangkat yang sama dengan IP lain tidak diperingatkan');

        $this->loginFrom($user, $iphone);
        $alert = $user->notifications()->first();
        $this->assertSame('login_new_device', $alert->data['event']);
        $this->assertSame('security', $alert->data['kind']);
        $this->assertStringContainsString('Safari di iOS', $alert->data['label']);
        $this->assertSame(2, LoginDevice::where('user_id', $user->id)->count());
    }

    // ---------- Riwayat aktivitas ----------

    public function test_activity_lists_only_own_actions_newest_first(): void
    {
        $user = $this->staff();
        $other = $this->staff('marketing');
        AuditLog::create(['actor_user_id' => $user->id, 'action' => 'profile.updated', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'changes' => ['x' => 1]]);
        AuditLog::create(['actor_user_id' => $user->id, 'action' => 'stock.adjusted', 'auditable_type' => 'App\\Models\\InventoryStock', 'auditable_id' => 5, 'changes' => []]);
        AuditLog::create(['actor_user_id' => $other->id, 'action' => 'news.created', 'auditable_type' => 'App\\Models\\NewsItem', 'auditable_id' => 1, 'changes' => []]);

        $response = $this->actingAs($user)->getJson('/api/v1/admin/profile/activity')->assertOk()->assertJsonPath('data.meta.total', 2);

        $this->assertSame(['stock.adjusted', 'profile.updated'], array_column($response->json('data.items'), 'action'));
        $this->assertSame('InventoryStock', $response->json('data.items.0.target'));
        $this->assertArrayNotHasKey('changes', $response->json('data.items.0'), 'isi perubahan tidak dikirim');
    }

    public function test_activity_requires_staff(): void
    {
        $this->getJson('/api/v1/admin/profile/activity')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/profile/activity')->assertForbidden();
    }

    // ---------- Preferensi notifikasi ----------

    public function test_preferences_list_only_events_for_the_role(): void
    {
        $this->actingAs($this->staff('warehouse'));

        $keys = array_column($this->getJson('/api/v1/admin/profile/notifications')->assertOk()->json('data.events'), 'key');

        $this->assertEqualsCanonicalizing(['order_paid', 'shipment_problem', 'low_stock'], $keys);
    }

    public function test_muting_an_event_stops_it_for_that_user_only(): void
    {
        $quiet = $this->staff('warehouse');
        $loud = $this->staff('warehouse');
        $this->actingAs($quiet)->putJson('/api/v1/admin/profile/notifications', ['muted' => ['low_stock']])->assertOk();

        app(StaffNotifier::class)->toRoles(['warehouse'], new StaffEvent('low_stock', 'stock', 'SKU-1', 'Stok menipis'));
        app(StaffNotifier::class)->toRoles(['warehouse'], new StaffEvent('order_paid', 'order', 'TF-1', 'Pesanan dibayar'));

        $this->assertSame(['order_paid'], $quiet->notifications()->get()->pluck('data.event')->all());
        $this->assertEqualsCanonicalizing(['low_stock', 'order_paid'], $loud->notifications()->get()->pluck('data.event')->all());
    }

    public function test_cannot_mute_unknown_or_other_roles_events(): void
    {
        $this->actingAs($this->staff('warehouse'));

        $this->putJson('/api/v1/admin/profile/notifications', ['muted' => ['cancellation_store_approved']])->assertUnprocessable()->assertJsonValidationErrors('muted.0');
        $this->putJson('/api/v1/admin/profile/notifications', ['muted' => ['login_new_device']])->assertUnprocessable();
        $this->putJson('/api/v1/admin/profile/notifications', [])->assertUnprocessable();
    }
}
