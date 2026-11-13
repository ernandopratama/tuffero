<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Otp\MetaWhatsAppOtpSender;
use App\Services\Otp\OtpSender;
use App\Services\PhoneOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * OTP WhatsApp tamu: parameter D-051 dan aturan hemat biaya D-093.
 */
class PhoneOtpTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+6281234567890';

    /** @var list<array{phone: string, code: string}> */
    public array $sent = [];

    public bool $failing = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpSender::class, new class($this) implements OtpSender
        {
            public function __construct(private PhoneOtpTest $test) {}

            public function send(string $phone, string $code): void
            {
                if ($this->test->failing) {
                    throw new RuntimeException('down', 500);
                }

                $this->test->sent[] = ['phone' => $phone, 'code' => $code];
            }
        });
    }

    private function send(array $data = [])
    {
        return $this->postJson('/api/v1/phone-otp/send', ['phone' => self::PHONE, ...$data]);
    }

    private function verify(string $code)
    {
        return $this->postJson('/api/v1/phone-otp/verify', ['phone' => self::PHONE, 'code' => $code]);
    }

    private function wrongCode(): string
    {
        return $this->sent[array_key_last($this->sent)]['code'] === '000000' ? '000001' : '000000';
    }

    public function test_sends_code_and_normalizes_local_number(): void
    {
        $this->send(['phone' => '081234567890'])->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.expires_in', 300)
            ->assertJsonPath('data.resend_in', 60);

        $this->assertCount(1, $this->sent);
        $this->assertSame(self::PHONE, $this->sent[0]['phone']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sent[0]['code']);
    }

    public function test_code_is_not_stored_in_plain_text(): void
    {
        $this->send()->assertOk();

        $entry = Cache::get('phone-otp:'.hash('sha256', self::PHONE));

        $this->assertIsArray($entry);
        $this->assertNotSame($this->sent[0]['code'], $entry['hash']);
        $this->assertNotSame(hash('sha256', $this->sent[0]['code']), $entry['hash']);
    }

    public function test_rejects_non_indonesian_number(): void
    {
        $this->send(['phone' => '+14155550100'])->assertStatus(422)->assertJsonPath('errors.phone.0', 'phone_invalid');

        $this->assertSame([], $this->sent);
    }

    public function test_verified_member_number_must_log_in_without_a_message(): void
    {
        User::factory()->create(['phone' => self::PHONE, 'phone_verified_at' => now()]);

        $this->send()->assertOk()->assertJsonPath('data.status', 'login_required');

        $this->assertSame([], $this->sent);
    }

    public function test_probe_reports_status_without_sending(): void
    {
        $this->send(['probe' => true])->assertOk()->assertJsonPath('data.status', 'required');
        $this->assertSame([], $this->sent);

        $this->send()->assertOk();
        $this->send(['probe' => true])->assertOk()->assertJsonPath('data.status', 'code_active');
        $this->assertCount(1, $this->sent);
    }

    public function test_active_code_is_not_sent_again_on_reload(): void
    {
        $this->send()->assertOk();
        $this->travel(10)->seconds();

        $this->send()->assertOk()->assertJsonPath('data.status', 'code_active')->assertJsonPath('data.resend_in', 50);

        $this->assertCount(1, $this->sent);
    }

    public function test_resend_waits_60_seconds_and_replaces_the_old_code(): void
    {
        $this->send()->assertOk();
        $this->send(['resend' => true])->assertStatus(429)->assertJsonPath('errors.phone.0', 'otp_resend_wait');

        $this->travel(61)->seconds();
        $this->send(['resend' => true])->assertOk()->assertJsonPath('data.status', 'sent');

        $this->assertCount(2, $this->sent);

        if ($this->sent[0]['code'] !== $this->sent[1]['code']) {
            $this->verify($this->sent[0]['code'])->assertStatus(422);
        }

        $this->verify($this->sent[1]['code'])->assertOk();
    }

    public function test_limits_three_sends_per_number_per_hour(): void
    {
        foreach (range(1, 3) as $i) {
            $this->send(['resend' => true])->assertOk();
            $this->travel(61)->seconds();
        }

        $this->send(['resend' => true])->assertStatus(429)->assertJsonPath('errors.phone.0', 'otp_send_limit');
        $this->assertCount(3, $this->sent);

        $this->travel(1)->hours();
        $this->send(['resend' => true])->assertOk();
    }

    public function test_limits_ten_sends_per_ip_per_hour(): void
    {
        foreach (range(10, 19) as $i) {
            $this->send(['phone' => '+62812345678'.$i])->assertOk();
        }

        $this->send(['phone' => '+6281234567820'])->assertStatus(429)->assertJsonPath('errors.phone.0', 'otp_send_limit');
        $this->assertCount(10, $this->sent);
    }

    public function test_correct_code_verifies_once_and_remembers_the_device(): void
    {
        $this->send()->assertOk();

        $response = $this->verify($this->sent[0]['code'])->assertOk()->assertJsonPath('data.verified', true);
        $cookie = $response->getCookie(PhoneOtpService::DEVICE_COOKIE, false);

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        $this->verify($this->sent[0]['code'])->assertStatus(422)->assertJsonPath('errors.code.0', 'otp_invalid');

        $this->withCredentials()->withCookie(PhoneOtpService::DEVICE_COOKIE, $response->getCookie(PhoneOtpService::DEVICE_COOKIE)->getValue());
        $this->send()->assertOk()->assertJsonPath('data.status', 'verified');
        $this->assertCount(1, $this->sent);
    }

    public function test_device_verification_expires_after_a_month_and_is_bound_to_the_number(): void
    {
        $service = app(PhoneOtpService::class);
        $value = $service->deviceCookie(self::PHONE)->getValue();

        $this->assertTrue($service->deviceVerified($value, self::PHONE));
        $this->assertFalse($service->deviceVerified($value, '+6281111111111'));
        $this->assertFalse($service->deviceVerified('not-json', self::PHONE));

        $this->travel(32)->days();
        $this->assertFalse($service->deviceVerified($value, self::PHONE));
    }

    public function test_five_wrong_attempts_lock_for_15_minutes(): void
    {
        $this->send()->assertOk();
        $wrong = $this->wrongCode();

        foreach (range(1, 5) as $i) {
            $this->verify($wrong)->assertStatus(422);
        }

        $this->verify($this->sent[0]['code'])->assertStatus(422);
        $this->send(['resend' => true])->assertStatus(429)->assertJsonPath('errors.phone.0', 'otp_locked');
        $this->assertCount(1, $this->sent);

        $this->travel(16)->minutes();
        $this->send(['resend' => true])->assertOk();
    }

    public function test_wrong_attempts_do_not_extend_the_code_lifetime(): void
    {
        $this->send()->assertOk();
        $this->travel(4)->minutes();
        $this->verify($this->wrongCode())->assertStatus(422);

        $this->travel(61)->seconds();
        $this->verify($this->sent[0]['code'])->assertStatus(422);
    }

    public function test_failed_delivery_reports_unavailable_and_allows_immediate_retry(): void
    {
        $this->failing = true;
        $this->send()->assertStatus(503)->assertJsonPath('errors.phone.0', 'otp_unavailable');

        $this->failing = false;
        $this->send()->assertOk()->assertJsonPath('data.status', 'sent');
    }

    public function test_failed_captcha_sends_nothing(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->send(['captcha_token' => 'bad'])->assertStatus(422)->assertJsonPath('errors.phone.0', 'captcha_failed');
        $this->send()->assertStatus(422);

        $this->assertSame([], $this->sent);
    }

    public function test_passing_captcha_allows_sending(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->send(['captcha_token' => 'good'])->assertOk()->assertJsonPath('data.status', 'sent');
    }

    public function test_meta_sender_uses_authentication_template_payload(): void
    {
        $this->configureMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        (new MetaWhatsAppOtpSender)->send(self::PHONE, '482913');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v24.0/123/messages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['to'] === '6281234567890'
            && $request['template']['name'] === 'tuffero_otp'
            && $request['template']['components'][0]['parameters'][0]['text'] === '482913'
            && $request['template']['components'][1]['sub_type'] === 'url');
    }

    public function test_meta_rejection_is_reported_as_failure(): void
    {
        $this->configureMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 400)]);
        $this->app->instance(OtpSender::class, new MetaWhatsAppOtpSender);

        $this->send()->assertStatus(503);
    }

    private function configureMeta(): void
    {
        config(['services.whatsapp' => [
            'token' => 'test-token', 'phone_number_id' => '123', 'otp_template' => 'tuffero_otp', 'otp_language' => 'id', 'graph_version' => 'v24.0',
        ]]);
    }
}
