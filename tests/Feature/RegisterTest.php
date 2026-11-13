<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RegisterEmailCode;
use App\Services\Otp\OtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Pendaftaran pelanggan: pilih verifikasi lewat email atau WhatsApp, akun baru dibuat hanya setelah kode benar.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'pelanggan.baru@example.com';

    private const PHONE = '+6281234567890';

    /** @var list<string> */
    public array $whatsappCodes = [];

    public bool $whatsappFailing = false;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->app->instance(OtpSender::class, new class($this) implements OtpSender
        {
            public function __construct(private RegisterTest $test) {}

            public function send(string $phone, string $code): void
            {
                if ($this->test->whatsappFailing) {
                    throw new RuntimeException('down', 500);
                }

                $this->test->whatsappCodes[] = $code;
            }
        });
    }

    private function start(array $data = [])
    {
        return $this->postJson('/api/v1/auth/register/send', ['email' => self::EMAIL, 'password' => 'rahasia-aman', 'channel' => 'email', ...$data]);
    }

    private function emailCode(): string
    {
        $code = '';
        Notification::assertSentOnDemand(RegisterEmailCode::class, function (RegisterEmailCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    public function test_email_registration_creates_a_verified_customer_and_logs_in(): void
    {
        $this->start()->assertOk()->assertJsonPath('data.channel', 'email')->assertJsonPath('data.status', 'sent');
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);

        $this->postJson('/api/v1/auth/register/verify', ['code' => $this->emailCode()])->assertOk();

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->mfa_required);
        $this->assertFalse($user->roles()->exists());
        $this->assertTrue(Hash::check('rahasia-aman', $user->password));
        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_whatsapp_registration_stores_the_verified_phone(): void
    {
        $this->start(['channel' => 'phone', 'phone' => '0812-3456-7890'])->assertOk()->assertJsonPath('data.channel', 'phone');
        Notification::assertNothingSent();

        $this->postJson('/api/v1/auth/register/verify', ['code' => $this->whatsappCodes[0]])->assertOk();

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(self::PHONE, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->email_verified_at);
    }

    public function test_unverified_phone_is_not_saved_when_verifying_by_email(): void
    {
        $this->start(['phone' => self::PHONE])->assertOk();
        $this->postJson('/api/v1/auth/register/verify', ['code' => $this->emailCode()])->assertOk();

        $this->assertNull(User::where('email', self::EMAIL)->firstOrFail()->phone);
    }

    public function test_whatsapp_channel_needs_a_phone_number(): void
    {
        $this->start(['channel' => 'phone'])->assertUnprocessable()->assertJsonPath('errors.phone.0', 'phone_invalid');
        $this->start(['channel' => 'sms'])->assertUnprocessable()->assertJsonPath('errors.channel.0', 'channel_invalid');
    }

    public function test_input_is_validated(): void
    {
        $this->start(['email' => 'bukan-email'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'email_invalid');
        $this->start(['password' => 'pendek'])->assertUnprocessable()->assertJsonPath('errors.password.0', 'password_invalid');
        Notification::assertNothingSent();
    }

    public function test_taken_email_or_phone_is_rejected_before_anything_is_sent(): void
    {
        User::factory()->create(['email' => self::EMAIL]);
        $this->start()->assertUnprocessable()->assertJsonPath('errors.email.0', 'email_taken');

        User::factory()->create(['phone' => self::PHONE, 'phone_verified_at' => now()]);
        $this->start(['email' => 'lain@example.com', 'channel' => 'phone', 'phone' => self::PHONE])->assertUnprocessable()->assertJsonPath('errors.phone.0', 'phone_taken');

        Notification::assertNothingSent();
        $this->assertSame([], $this->whatsappCodes);
    }

    public function test_wrong_code_is_rejected_and_five_misses_lock_the_email(): void
    {
        $this->start()->assertOk();
        $real = $this->emailCode();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/register/verify', ['code' => $this->wrongCode($real)])->assertUnprocessable()->assertJsonPath('errors.code.0', 'otp_invalid');
        }

        $this->postJson('/api/v1/auth/register/verify', ['code' => $real])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
        $this->assertGuest('web');
    }

    public function test_verify_without_a_started_registration_fails(): void
    {
        $this->postJson('/api/v1/auth/register/verify', ['code' => '123456'])->assertUnprocessable()->assertJsonPath('errors.otp.0', 'otp_expired');
        $this->postJson('/api/v1/auth/register/resend')->assertUnprocessable()->assertJsonPath('errors.otp.0', 'otp_expired');
    }

    public function test_another_browser_cannot_finish_or_hijack_a_registration(): void
    {
        $this->start()->assertOk();
        $real = $this->emailCode();

        // Peramban lain (sesi lain) menimpa data pendaftaran dengan kata sandinya sendiri: tidak memengaruhi sesi pertama.
        $this->flushSession();
        $this->start(['password' => 'milik-penyerang'])->assertOk()->assertJsonPath('data.status', 'code_active');

        $this->flushSession();
        $this->postJson('/api/v1/auth/register/verify', ['code' => $real])->assertUnprocessable()->assertJsonPath('errors.otp.0', 'otp_expired');
    }

    public function test_resend_waits_sixty_seconds_and_a_failed_send_does_not_hold_the_customer(): void
    {
        $this->start()->assertOk();
        $this->postJson('/api/v1/auth/register/resend')->assertStatus(429)->assertJsonPath('errors.otp.0', 'otp_resend_wait');

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/register/resend')->assertOk()->assertJsonPath('data.status', 'sent');
        Notification::assertSentOnDemandTimes(RegisterEmailCode::class, 2);
    }

    public function test_sending_again_without_resend_reuses_the_active_code(): void
    {
        $this->start()->assertOk();
        $this->start()->assertOk()->assertJsonPath('data.status', 'code_active');
        Notification::assertSentOnDemandTimes(RegisterEmailCode::class, 1);
    }

    public function test_hourly_send_limit_per_email(): void
    {
        $this->start()->assertOk();
        foreach ([1, 2] as $i) {
            $this->travel(61)->seconds();
            $this->postJson('/api/v1/auth/register/resend')->assertOk();
        }

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/register/resend')->assertStatus(429)->assertJsonPath('errors.otp.0', 'otp_send_limit');
        Notification::assertSentOnDemandTimes(RegisterEmailCode::class, 3);
    }

    public function test_provider_failure_is_reported_without_details_and_releases_the_resend_lock(): void
    {
        $this->whatsappFailing = true;
        $this->start(['channel' => 'phone', 'phone' => self::PHONE])->assertStatus(503)->assertJsonPath('errors.phone.0', 'otp_unavailable');

        $this->whatsappFailing = false;
        $this->postJson('/api/v1/auth/register/resend')->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertCount(1, $this->whatsappCodes);
    }

    public function test_code_is_not_stored_in_plain_text(): void
    {
        $this->start()->assertOk();
        $real = $this->emailCode();

        $entry = Cache::get('register-email:'.hash('sha256', self::EMAIL));
        $this->assertStringNotContainsString($real, json_encode($entry));
    }
}
