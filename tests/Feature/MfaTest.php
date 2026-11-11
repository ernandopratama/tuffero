<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\MfaEmailCode;
use App\Services\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Test-Only-Password-123';

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD), ...$attributes]);
    }

    private function logIn(User $user): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    private function currentCode(User $user): string
    {
        return app(MfaService::class)->totp($user->fresh()->mfa_totp_secret, (int) floor(now()->getTimestamp() / 30));
    }

    /** @return list<string> */
    private function enroll(User $user): array
    {
        $this->logIn($user);
        $this->postJson('/api/v1/auth/mfa/setup')->assertOk();
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->currentCode($user)])->assertOk()->json('data.recovery_codes');

        auth('web')->logout();
        $this->flushSession();
        $this->travel(31)->seconds();

        return $codes;
    }

    public function test_totp_matches_rfc_6238_vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $service = app(MfaService::class);

        $this->assertSame('287082', $service->totp($secret, (int) floor(59 / 30)));
        $this->assertSame('081804', $service->totp($secret, (int) floor(1111111109 / 30)));
        $this->assertSame('005924', $service->totp($secret, (int) floor(1234567890 / 30)));
    }

    public function test_password_alone_does_not_create_a_session(): void
    {
        $user = $this->user();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.mfa', 'setup')
            ->assertJsonMissingPath('data.user');

        $this->assertGuest('web');
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_mfa_endpoints_require_password_step_first(): void
    {
        $this->postJson('/api/v1/auth/mfa/setup')->assertUnauthorized();
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '123456'])->assertUnauthorized();
    }

    public function test_pending_login_expires(): void
    {
        $user = $this->user();
        $this->logIn($user);
        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/mfa/setup')->assertUnauthorized();
    }

    public function test_enrollment_activates_totp_and_stores_secrets_safely(): void
    {
        $user = $this->user();
        $this->logIn($user);

        $setup = $this->postJson('/api/v1/auth/mfa/setup')->assertOk();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setup->json('data.qr_code'));
        $this->assertStringContainsString('issuer=Tuffero', $setup->json('data.otpauth_uri'));

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->assertGuest('web');

        $response = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->currentCode($user)])
            ->assertOk()
            ->assertJsonCount(8, 'data.recovery_codes')
            ->assertJsonMissingPath('data.user.mfa_totp_secret');

        $this->assertAuthenticatedAs($user, 'web');

        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame($setup->json('data.secret'), $raw->mfa_totp_secret);
        $this->assertStringNotContainsString($response->json('data.recovery_codes.0'), $raw->mfa_recovery_codes);
        $this->assertSame('mfa.enrolled', AuditLog::where('auditable_id', $user->id)->value('action'));
    }

    public function test_enrolled_authenticator_cannot_be_replaced_with_password_alone(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $secret = $user->fresh()->mfa_totp_secret;

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertJsonPath('data.mfa', 'challenge');

        $this->postJson('/api/v1/auth/mfa/setup')->assertStatus(409);
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456'])->assertStatus(409);
        $this->assertSame($secret, $user->fresh()->mfa_totp_secret);
    }

    public function test_verify_completes_login_and_rejects_replay(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $this->logIn($user);
        $code = $this->currentCode($user);

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertUnprocessable();
        $this->assertGuest('web');

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $code])->assertOk()->assertJsonPath('data.user.email', $user->email);
        $this->assertAuthenticatedAs($user, 'web');

        auth('web')->logout();
        $this->flushSession();
        $this->logIn($user);

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $code])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_recovery_code_works_once(): void
    {
        $user = $this->user();
        $codes = $this->enroll($user);
        $this->logIn($user);

        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => 'salah-salah'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => strtoupper($codes[0])])->assertOk();
        $this->assertCount(7, $user->fresh()->mfa_recovery_codes);

        auth('web')->logout();
        $this->flushSession();
        $this->logIn($user);

        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $codes[0]])->assertUnprocessable();
        $this->assertSame(1, AuditLog::where('action', 'mfa.recovery_code_used')->count());
    }

    public function test_code_guessing_is_rate_limited_per_account(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $this->travel(2)->minutes();
        $this->logIn($user);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->currentCode($user)])->assertTooManyRequests();
        $this->assertGuest('web');
    }

    public function test_deactivated_user_cannot_finish_pending_login(): void
    {
        $user = $this->user();
        $this->logIn($user);
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/mfa/setup')->assertUnauthorized();
    }

    public function test_user_without_mfa_requirement_logs_in_directly(): void
    {
        $user = $this->user(['mfa_required' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_reset_command_clears_mfa_and_records_audit(): void
    {
        $user = $this->user();
        $this->enroll($user);

        $this->artisan('admin:reset-mfa', ['email' => $user->email])
            ->expectsConfirmation("Hapus MFA {$user->email}? Login berikutnya wajib mendaftar ulang.", 'yes')
            ->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertNull($fresh->mfa_totp_secret);
        $this->assertNull($fresh->mfa_totp_confirmed_at);
        $this->assertNull($fresh->mfa_recovery_codes);
        $this->assertSame(1, AuditLog::where('action', 'mfa.reset')->count());

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertJsonPath('data.mfa', 'setup');
    }

    private function sentEmailCode(User $user): string
    {
        $code = '';
        Notification::assertSentTo($user, MfaEmailCode::class, function (MfaEmailCode $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    public function test_email_code_completes_login_once(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->enroll($user);
        $this->logIn($user);

        $this->postJson('/api/v1/auth/mfa/email')->assertOk();
        $code = $this->sentEmailCode($user);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->postJson('/api/v1/auth/mfa/verify', ['email_code' => $code])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(1, AuditLog::where('action', 'mfa.email_code_used')->count());

        auth('web')->logout();
        $this->flushSession();
        $this->logIn($user);
        $this->postJson('/api/v1/auth/mfa/verify', ['email_code' => $code])->assertStatus(422);
    }

    public function test_email_code_needs_enrolled_totp_and_waits_before_resend(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->logIn($user);
        $this->postJson('/api/v1/auth/mfa/email')->assertStatus(409);
        Notification::assertNothingSent();

        $this->enroll($user);
        $this->logIn($user);
        $this->postJson('/api/v1/auth/mfa/email')->assertOk();
        $this->postJson('/api/v1/auth/mfa/email')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/mfa/email')->assertOk();
        Notification::assertSentToTimes($user, MfaEmailCode::class, 2);
    }

    public function test_email_code_locks_after_five_wrong_attempts_and_expires(): void
    {
        Notification::fake();
        $user = $this->user();
        $service = app(MfaService::class);

        $service->sendEmailCode($user);
        $code = $this->sentEmailCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->verifyEmailCode($user, $wrong));
        }
        $this->assertFalse($service->verifyEmailCode($user, $code));

        $this->travel(16)->minutes();
        Notification::fake();
        $service->sendEmailCode($user);
        $code = $this->sentEmailCode($user);
        $this->travel(6)->minutes();
        $this->assertFalse($service->verifyEmailCode($user, $code));
    }

    public function test_failed_email_send_returns_503_without_details(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $this->logIn($user);
        $this->mock(MfaService::class)->shouldReceive('sendEmailCode')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/v1/auth/mfa/email')->assertStatus(503)->assertJsonMissing(['smtp down']);
    }
}
