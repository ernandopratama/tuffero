<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\RegisterEmailCode;
use App\Services\Otp\OtpSender;
use App\Services\RegistrationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Saklar pendaftaran registration.whatsapp_required (D-146): mati (bawaan) = cukup email, nomor tidak diminta dan tidak ada
 * pesan WhatsApp berbiaya yang bisa dipicu; nyala = email dan WhatsApp keduanya wajib (D-145, diuji di RegisterTest).
 */
class RegisterWhatsappSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'pelanggan.baru@example.com';

    private const PHONE = '+6281234567890';

    private const KEY_URL = '/api/v1/admin/settings/registration.whatsapp_required';

    /** @var list<string> */
    public array $whatsappCodes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $this->app->instance(OtpSender::class, $this->workingSender());
    }

    private function workingSender(): OtpSender
    {
        return new class($this) implements OtpSender
        {
            public function __construct(private RegisterWhatsappSwitchTest $test) {}

            public function send(string $phone, string $code): void
            {
                $this->test->whatsappCodes[] = $code;
            }
        };
    }

    private function switchTo(bool $on): void
    {
        Setting::where('key', RegistrationService::SETTING_KEY)->update(['value' => $on ? '1' : '0']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
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

    public function test_switch_is_off_by_default(): void
    {
        $this->assertFalse(RegistrationService::whatsappRequired());
        $this->assertDatabaseHas('settings', ['key' => RegistrationService::SETTING_KEY, 'value' => '0', 'group' => 'registration', 'is_public' => false]);
    }

    public function test_email_code_alone_creates_the_account_without_a_phone_number(): void
    {
        $this->start()->assertOk()->assertJsonPath('data.channel', 'email')->assertJsonPath('data.status', 'sent');
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);

        $this->postJson('/api/v1/auth/register/verify', ['code' => $this->emailCode()])
            ->assertOk()->assertJsonPath('data.registered', true);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $this->whatsappCodes);
    }

    public function test_a_phone_number_sent_by_an_old_form_is_ignored_and_never_stored(): void
    {
        User::factory()->create(['phone' => self::PHONE, 'phone_verified_at' => now()]);

        $this->start(['phone' => self::PHONE])->assertOk();
        $this->postJson('/api/v1/auth/register/verify', ['code' => $this->emailCode()])->assertOk()->assertJsonPath('data.registered', true);

        $this->assertNull(User::where('email', self::EMAIL)->firstOrFail()->phone);
        $this->assertSame([], $this->whatsappCodes);
    }

    public function test_the_whatsapp_channel_is_refused_and_sends_nothing(): void
    {
        $this->start(['channel' => 'phone', 'phone' => self::PHONE])->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->start()->assertOk();
        $this->postJson('/api/v1/auth/register/channel', ['channel' => 'phone'])->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->assertSame([], $this->whatsappCodes);
    }

    public function test_turning_the_switch_off_mid_flow_closes_a_running_whatsapp_session(): void
    {
        $this->switchTo(true);
        $this->start(['channel' => 'phone', 'phone' => self::PHONE])->assertOk()->assertJsonPath('data.channel', 'phone');
        $this->assertCount(1, $this->whatsappCodes);

        $this->switchTo(false);
        $this->travel(2)->minutes();

        $this->postJson('/api/v1/auth/register/resend')->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->assertCount(1, $this->whatsappCodes);
    }

    public function test_switch_on_requires_a_phone_and_both_codes(): void
    {
        $this->switchTo(true);

        $this->start()->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->start(['phone' => self::PHONE])->assertOk();
        $this->postJson('/api/v1/auth/register/verify', ['code' => $this->emailCode()])
            ->assertOk()->assertJsonPath('data.registered', false);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_only_super_admin_can_change_it(): void
    {
        foreach (['store-admin', 'owner', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->patchJson(self::KEY_URL, ['value' => 1])->assertForbidden();
        }

        $super = $this->staff('super-admin');
        $this->actingAs($super)->patchJson(self::KEY_URL, ['value' => 2])->assertUnprocessable();
        $this->actingAs($super)->patchJson(self::KEY_URL, ['value' => 1])->assertOk();
        $this->assertTrue(RegistrationService::whatsappRequired());
        $this->actingAs($super)->patchJson(self::KEY_URL, ['value' => 0])->assertOk();
        $this->assertFalse(RegistrationService::whatsappRequired());
    }

    public function test_it_cannot_be_turned_on_before_whatsapp_and_turnstile_are_configured_but_can_always_be_turned_off(): void
    {
        $super = $this->staff('super-admin');

        // Lingkungan production: tes lain memakai "testing", yang selalu dianggap siap. CSRF dimatikan karena hanya aktif di luar "testing".
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['services.whatsapp' => ['token' => null, 'phone_number_id' => null, 'otp_template' => null], 'services.turnstile' => ['secret' => null]]);

        $this->actingAs($super)->patchJson(self::KEY_URL, ['value' => 1])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->assertFalse(RegistrationService::whatsappRequired());

        // Kunci WhatsApp saja belum cukup: Turnstile juga wajib di luar local.
        config(['services.whatsapp' => ['token' => 'x', 'phone_number_id' => '1', 'otp_template' => 'tuffero_otp']]);
        $this->actingAs($super)->patchJson(self::KEY_URL, ['value' => 1])->assertUnprocessable();

        config(['services.turnstile' => ['secret' => 'y']]);
        $this->actingAs($super)->patchJson(self::KEY_URL, ['value' => 1])->assertOk();
        $this->assertTrue(RegistrationService::whatsappRequired());

        // Mematikan selalu boleh, termasuk saat kunci hilang.
        config(['services.whatsapp' => ['token' => null, 'phone_number_id' => null, 'otp_template' => null]]);
        $this->actingAs($super)->patchJson(self::KEY_URL, ['value' => 0])->assertOk();
        $this->assertFalse(RegistrationService::whatsappRequired());
    }
}
