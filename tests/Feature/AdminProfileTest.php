<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ProfileChangeCode;
use App\Services\Otp\OtpSender;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Kata-Sandi-Lama-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role = 'store-admin', array $attributes = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD, 'mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    public function test_profile_shows_only_own_non_secret_data(): void
    {
        $user = $this->staff('owner', ['mfa_recovery_codes' => ['a', 'b', 'c']]);

        $response = $this->actingAs($user)->getJson('/api/v1/admin/profile')->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.mfa.enabled', true)
            ->assertJsonPath('data.mfa.recovery_codes_left', 3);

        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('mfa_totp_secret', $response->getContent());
    }

    public function test_guests_roleless_and_inactive_accounts_are_refused(): void
    {
        $this->getJson('/api/v1/admin/profile')->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('/api/v1/admin/profile')->assertForbidden();
        $this->actingAs($customer)->patchJson('/api/v1/admin/profile', ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
        $this->actingAs($customer)->putJson('/api/v1/admin/profile/password', [])->assertForbidden();

        $this->actingAs($this->staff('owner', ['is_active' => false]))->getJson('/api/v1/admin/profile')->assertUnauthorized();
    }

    public function test_name_can_change_without_the_current_password_and_is_audited(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->patchJson('/api/v1/admin/profile', ['name' => 'Nama Baru', 'email' => $user->email])
            ->assertOk()->assertJsonPath('data.user.name', 'Nama Baru');

        $this->assertSame('Nama Baru', $user->fresh()->name);
        $log = AuditLog::where('action', 'profile.updated')->firstOrFail();
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame('Nama Baru', $log->changes['name']['new']);
    }

    public function test_changing_email_needs_the_current_password(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        $this->patchJson('/api/v1/admin/profile', ['name' => $user->name, 'email' => 'baru@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->patchJson('/api/v1/admin/profile', ['name' => $user->name, 'email' => 'baru@example.com', 'current_password' => 'salah-total-1A'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertNotSame('baru@example.com', $user->fresh()->email);

        $this->patchJson('/api/v1/admin/profile', ['name' => $user->name, 'email' => 'baru@example.com', 'current_password' => self::PASSWORD])->assertOk();
        $this->assertSame('baru@example.com', $user->fresh()->email);
    }

    public function test_email_must_stay_unique_and_valid(): void
    {
        $other = $this->staff('marketing');
        $user = $this->staff();

        $this->actingAs($user)->patchJson('/api/v1/admin/profile', ['name' => $user->name, 'email' => $other->email, 'current_password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->patchJson('/api/v1/admin/profile', ['name' => '', 'email' => 'bukan-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_profile_update_ignores_role_status_and_mfa_fields(): void
    {
        $user = $this->staff('store-admin');

        $this->actingAs($user)->patchJson('/api/v1/admin/profile', ['name' => $user->name, 'email' => $user->email, 'role' => 'super-admin', 'is_active' => false, 'mfa_required' => false])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('store-admin'));
        $this->assertFalse($fresh->hasRole('super-admin'));
        $this->assertTrue($fresh->is_active);
    }

    public function test_password_change_enforces_current_password_policy_and_confirmation(): void
    {
        $user = $this->staff();
        $this->actingAs($user);
        $good = ['current_password' => self::PASSWORD, 'password' => 'Password-Baru-12', 'password_confirmation' => 'Password-Baru-12'];

        $this->putJson('/api/v1/admin/profile/password', [...$good, 'current_password' => 'salah'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/admin/profile/password', [...$good, 'password' => 'pendek1A', 'password_confirmation' => 'pendek1A'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/admin/profile/password', [...$good, 'password' => 'semuahurufkecil12', 'password_confirmation' => 'semuahurufkecil12'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/admin/profile/password', [...$good, 'password_confirmation' => 'Berbeda-Banget-12'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/admin/profile/password', ['current_password' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));

        // Lima percobaan gagal sudah menghabiskan batas per menit; tunggu jendela berikutnya.
        $this->travel(2)->minutes();
        $this->putJson('/api/v1/admin/profile/password', $good)->assertOk();
        $this->assertTrue(Hash::check('Password-Baru-12', $user->fresh()->password));
    }

    public function test_password_change_ends_other_sessions_but_keeps_this_one_and_never_logs_the_password(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->staff();
        $stranger = $this->staff('marketing');

        foreach ([['other-device', $user->id], ['stranger-device', $stranger->id]] as [$id, $owner]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }

        $this->actingAs($user)->putJson('/api/v1/admin/profile/password', ['current_password' => self::PASSWORD, 'password' => 'Password-Baru-12', 'password_confirmation' => 'Password-Baru-12'])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'stranger-device']);

        $log = AuditLog::where('action', 'profile.password_changed')->firstOrFail();
        $this->assertStringNotContainsString('Password-Baru-12', json_encode($log->toArray()));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($log->toArray()));
    }

    public function test_guessing_the_current_password_is_rate_limited(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        foreach (range(1, 5) as $i) {
            $this->putJson('/api/v1/admin/profile/password', ['current_password' => "tebak-{$i}", 'password' => 'Password-Baru-12', 'password_confirmation' => 'Password-Baru-12'])->assertUnprocessable();
        }

        $this->putJson('/api/v1/admin/profile/password', ['current_password' => self::PASSWORD, 'password' => 'Password-Baru-12', 'password_confirmation' => 'Password-Baru-12'])->assertStatus(429);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    /** Menangkap kode OTP yang dikirim ke WhatsApp (pengirim palsu); email lewat Notification::fake(). */
    private function captureCodes(): object
    {
        Notification::fake();
        $sender = new class implements OtpSender
        {
            public ?string $code = null;

            public function send(string $phone, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(OtpSender::class, $sender);

        return $sender;
    }

    private function emailCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, ProfileChangeCode::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    public function test_username_change_needs_an_email_code_and_is_audited(): void
    {
        $this->captureCodes();
        $user = $this->staff('marketing', ['username' => 'lama']);
        $this->actingAs($user);

        $this->putJson('/api/v1/admin/profile/username', ['username' => 'Baru.Nama', 'channel' => 'email', 'code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame('lama', $user->fresh()->username);

        $this->postJson('/api/v1/admin/profile/username/code', ['channel' => 'email'])->assertOk();
        $code = $this->emailCode($user);

        $this->putJson('/api/v1/admin/profile/username', ['username' => 'Baru.Nama', 'channel' => 'email', 'code' => $code])->assertOk()->assertJsonPath('data.username', 'baru.nama');
        $this->assertSame('baru.nama', $user->fresh()->username);
        $this->assertSame(['old' => 'lama', 'new' => 'baru.nama'], AuditLog::where('action', 'profile.username_changed')->firstOrFail()->changes['username']);

        // Kode hanya sekali pakai.
        $this->putJson('/api/v1/admin/profile/username', ['username' => 'lain', 'channel' => 'email', 'code' => $code])->assertUnprocessable();
        $this->assertSame('baru.nama', $user->fresh()->username);
    }

    public function test_whatsapp_code_works_only_for_a_verified_phone(): void
    {
        $sender = $this->captureCodes();
        $user = $this->staff('owner', ['phone' => '+6281200000001', 'phone_verified_at' => null]);
        $this->actingAs($user);

        $this->postJson('/api/v1/admin/profile/username/code', ['channel' => 'phone'])->assertUnprocessable();
        $this->assertNull($sender->code);

        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->postJson('/api/v1/admin/profile/username/code', ['channel' => 'phone'])->assertOk();
        $this->assertNotNull($sender->code);

        // Kode WhatsApp tidak berlaku untuk saluran email.
        $this->putJson('/api/v1/admin/profile/username', ['username' => 'owner.baru', 'channel' => 'email', 'code' => $sender->code])->assertUnprocessable();
        $this->putJson('/api/v1/admin/profile/username', ['username' => 'owner.baru', 'channel' => 'phone', 'code' => $sender->code])->assertOk();
        $this->assertSame('owner.baru', $user->fresh()->username);
    }

    public function test_username_code_is_bound_to_the_account_locks_after_wrong_tries_and_rejects_taken_names(): void
    {
        $this->captureCodes();
        $user = $this->staff('marketing');
        $other = $this->staff('owner', ['username' => 'dipakai']);

        $this->actingAs($user)->postJson('/api/v1/admin/profile/username/code', ['channel' => 'email'])->assertOk();
        $code = $this->emailCode($user);

        // Akun lain tidak bisa memakai kode ini.
        $this->actingAs($other)->putJson('/api/v1/admin/profile/username', ['username' => 'curian', 'channel' => 'email', 'code' => $code])->assertUnprocessable();
        $this->assertSame('dipakai', $other->fresh()->username);

        $this->actingAs($user)->putJson('/api/v1/admin/profile/username', ['username' => 'dipakai', 'channel' => 'email', 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('username');

        // Lima kode salah mengunci saluran; kode yang benar pun ditolak. Batas laju rute dilepas supaya yang diuji kuncinya.
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (range(1, 5) as $try) {
            $this->actingAs($user)->putJson('/api/v1/admin/profile/username', ['username' => 'aman', 'channel' => 'email', 'code' => '000000'])->assertUnprocessable();
        }
        $this->actingAs($user)->putJson('/api/v1/admin/profile/username', ['username' => 'aman', 'channel' => 'email', 'code' => $code])->assertUnprocessable();
        $this->assertNull($user->fresh()->username);
    }

    public function test_generic_profile_update_cannot_change_username_and_roleless_accounts_are_refused(): void
    {
        $user = $this->staff('marketing', ['username' => 'tetap']);

        $this->actingAs($user)->patchJson('/api/v1/admin/profile', ['name' => 'Baru', 'email' => $user->email, 'username' => 'bypass'])->assertOk();
        $this->assertSame('tetap', $user->fresh()->username);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/admin/profile/username/code', ['channel' => 'email'])->assertForbidden();
    }
}
