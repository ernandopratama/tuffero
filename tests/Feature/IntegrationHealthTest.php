<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookRejection;
use App\Services\IntegrationHealthService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        config([
            'services.midtrans.server_key' => 'SB-Mid-server-rahasia123',
            'services.midtrans.client_key' => 'SB-Mid-client-publik123',
            'services.midtrans.is_production' => false,
            'services.biteship.key' => 'biteship_test.kunci-rahasia',
            'services.biteship.origin_postal_code' => '65111',
            'services.biteship.webhook_secret' => 'rahasia-webhook-yang-panjang-sekali',
        ]);
        $this->configureServices();
    }

    /** Layanan pendukung lengkap dan siap di environment non-lokal; WhatsApp dan Claude sengaja kosong (tidak dipakai). */
    private function configureServices(array $override = []): void
    {
        config([
            'mail.default' => 'resend',
            'services.resend.key' => 're_kunci-rahasia-email',
            'mail.from.address' => 'toko@tuffero.com',
            'services.turnstile.secret' => 'ts-rahasia-turnstile',
            'services.turnstile.site_key' => 'site-key-publik',
            'services.whatsapp.token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.otp_template' => null,
            'services.whatsapp.otp_required' => false,
            'services.anthropic.key' => null,
            ...$override,
        ]);
    }

    /** @return array<string, array<string, mixed>> layanan pendukung menurut kunci */
    private function services(): array
    {
        return collect($this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/integrations')->assertOk()->json('data.services'))->keyBy('key')->all();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array<string, mixed>> baris integrasi menurut judul */
    private function rows(): array
    {
        $data = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        return collect(collect($data['lists'])->firstWhere('key', 'integrations')['rows'])->keyBy('title')->all();
    }

    public function test_rejected_biteship_webhook_is_recorded_without_payload_or_secret(): void
    {
        $this->postJson('/api/v1/webhooks/biteship', ['event' => 'order.status', 'order_id' => 'x'], ['X-Biteship-Secret' => 'salah-sekali-rahasia'])->assertForbidden();

        $row = WebhookRejection::firstOrFail();
        $this->assertSame('biteship', $row->provider);
        $this->assertSame('invalid_secret', $row->reason);
        $this->assertStringNotContainsString('salah-sekali-rahasia', json_encode($row->toArray()));
    }

    public function test_rejected_midtrans_webhook_is_recorded_by_reason(): void
    {
        $this->postJson('/api/v1/webhooks/midtrans', ['foo' => 'bar'])->assertForbidden();
        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => 'ORD-1', 'status_code' => '200', 'gross_amount' => '1000.00', 'transaction_id' => 't1', 'transaction_status' => 'settlement', 'signature_key' => 'palsu',
        ])->assertForbidden();

        $this->assertEqualsCanonicalizing(['invalid_payload', 'invalid_signature'], WebhookRejection::where('provider', 'midtrans')->pluck('reason')->all());
    }

    public function test_repeated_rejections_from_one_source_are_stored_once_per_minute(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/webhooks/midtrans', ['foo' => 'bar'])->assertForbidden();
        }

        $this->assertSame(1, WebhookRejection::count());
    }

    public function test_dashboard_warns_about_recent_rejections_and_ignores_old_ones(): void
    {
        $this->assertSame('Siap', $this->rows()['Biteship']['chip']['text']);

        WebhookRejection::create(['provider' => 'biteship', 'reason' => 'invalid_secret', 'ip_address' => '203.0.113.9']);

        $rows = $this->rows();
        $this->assertSame('Periksa', $rows['Biteship']['chip']['text']);
        $this->assertStringContainsString('1 webhook ditolak', $rows['Biteship']['subtitle']);
        $this->assertSame('Siap', $rows['Midtrans']['chip']['text']);

        WebhookRejection::query()->update(['created_at' => now()->subDays(2)]);
        $this->assertSame('Siap', $this->rows()['Biteship']['chip']['text']);
    }

    public function test_live_check_marks_both_providers_ready_when_keys_are_accepted(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response(['status_code' => '404', 'status_message' => 'Transaction doesn\'t exist.'], 404),
            'api.biteship.com/*' => Http::response(['success' => true, 'couriers' => []]),
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
        ]);

        $response = $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/integrations/check')->assertOk();

        $this->assertSame('ok', $response->json('data.midtrans.status'));
        $this->assertSame('ok', $response->json('data.biteship.status'));
        $this->assertNotNull($this->getJson('/api/v1/admin/integrations')->json('data.integrations.0.checked_at'));
        $this->assertSame('Siap', $this->rows()['Midtrans']['chip']['text']);
    }

    public function test_live_check_flags_rejected_keys_without_leaking_secrets(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response(['status_code' => '401', 'status_message' => 'Unauthorized'], 200),
            'api.biteship.com/*' => Http::response(['success' => false, 'error' => 'Invalid API key rahasia-webhook-yang-panjang-sekali'], 401),
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
        ]);

        $response = $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/integrations/check')->assertOk();

        $this->assertSame('bad', $response->json('data.midtrans.status'));
        $this->assertSame('bad', $response->json('data.biteship.status'));
        foreach (['SB-Mid-server-rahasia123', 'biteship_test.kunci-rahasia', 'rahasia-webhook-yang-panjang-sekali'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $rows = $this->rows();
        $this->assertSame('Bermasalah', $rows['Midtrans']['chip']['text']);
        $this->assertSame('Bermasalah', $rows['Biteship']['chip']['text']);
    }

    public function test_live_check_treats_network_failure_as_unknown_not_bad(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $response = $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/integrations/check')->assertOk();

        $this->assertSame('unknown', $response->json('data.midtrans.status'));
        $this->assertSame('Periksa', $this->rows()['Midtrans']['chip']['text']);
    }

    public function test_sandbox_key_without_sb_prefix_is_not_flagged(): void
    {
        config(['services.midtrans.server_key' => 'Mid-server-format-baru-sandbox', 'services.midtrans.is_production' => false]);

        $this->assertSame('Siap', $this->rows()['Midtrans']['chip']['text']);
    }

    public function test_settings_page_endpoint_lists_status_and_webhook_urls_without_secrets(): void
    {
        Http::fake();

        $response = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/integrations')->assertOk();

        $items = collect($response->json('data.integrations'))->keyBy('key');
        $this->assertSame(['midtrans', 'biteship'], $items->keys()->all());
        $this->assertStringEndsWith('/api/v1/webhooks/midtrans', $items['midtrans']['webhook_url']);
        $this->assertStringEndsWith('/api/v1/webhooks/biteship', $items['biteship']['webhook_url']);
        $this->assertSame('ok', $items['midtrans']['status']);
        $this->assertNull($items['midtrans']['checked_at']);
        foreach (['SB-Mid-server-rahasia123', 'SB-Mid-client-publik123', 'biteship_test.kunci-rahasia', 'rahasia-webhook-yang-panjang-sekali'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_webhook_address_follows_app_url_in_dev_and_production(): void
    {
        foreach (['https://tuffero.ernando.my.id', 'https://tuffero.com'] as $url) {
            config(['app.url' => $url]);

            $data = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/integrations')->assertOk()->json('data');

            $this->assertSame($url.'/api/v1/webhooks/midtrans', $data['integrations'][0]['webhook_url']);
            $this->assertSame($url.'/api/v1/webhooks/biteship', $data['integrations'][1]['webhook_url']);
            $this->assertSame('app_url', $data['webhook_source']);
            $this->assertTrue($data['webhook_reachable']);
        }
    }

    public function test_webhook_address_uses_the_active_tunnel_only_in_local_environment(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $file = IntegrationHealthService::tunnelFile();
        config(['app.url' => 'http://localhost:8000']);
        file_put_contents($file, 'https://contoh-tunnel-123.trycloudflare.com');

        try {
            $admin = $this->actingAs($this->staff('super-admin'));

            // Di luar local, berkas tunnel diabaikan dan localhost ditandai tidak terjangkau.
            $data = $admin->getJson('/api/v1/admin/integrations')->json('data');
            $this->assertSame('app_url', $data['webhook_source']);
            $this->assertFalse($data['webhook_reachable']);

            $this->app->detectEnvironment(fn () => 'local');
            $data = $admin->getJson('/api/v1/admin/integrations')->json('data');
            $this->assertSame('tunnel', $data['webhook_source']);
            $this->assertSame('https://contoh-tunnel-123.trycloudflare.com/api/v1/webhooks/midtrans', $data['integrations'][0]['webhook_url']);

            // Isi berkas yang bukan alamat tunnel Cloudflare tidak dipercaya.
            file_put_contents($file, 'https://jahat.example.com');
            $this->assertSame('app_url', $admin->getJson('/api/v1/admin/integrations')->json('data.webhook_source'));
        } finally {
            @unlink($file);
        }
    }

    public function test_running_tunnel_is_detected_from_cloudflared_in_local_environment(): void
    {
        Http::fake([
            '127.0.0.1:20241/*' => Http::response('', 404),
            '127.0.0.1:20242/quicktunnel' => Http::response(['hostname' => 'tunnel-aktif-456.trycloudflare.com']),
        ]);
        $this->app->detectEnvironment(fn () => 'local');

        $data = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/integrations')->assertOk()->json('data');

        $this->assertTrue($data['local']);
        $this->assertSame('https://tunnel-aktif-456.trycloudflare.com', $data['tunnel_url']);
        $this->assertSame('https://tunnel-aktif-456.trycloudflare.com/api/v1/webhooks/biteship', $data['integrations'][1]['webhook_url']);
    }

    public function test_no_tunnel_is_reported_when_cloudflared_is_not_running(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $this->app->detectEnvironment(fn () => 'local');

        $data = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/integrations')->assertOk()->json('data');

        $this->assertTrue($data['local']);
        $this->assertNull($data['tunnel_url']);
        $this->assertSame('app_url', $data['webhook_source']);
    }

    public function test_tunnel_is_never_looked_up_outside_local(): void
    {
        Http::fake();

        $data = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/integrations')->assertOk()->json('data');

        $this->assertFalse($data['local']);
        $this->assertNull($data['tunnel_url']);
        Http::assertNothingSent();
    }

    public function test_settings_page_endpoint_is_only_for_super_admin(): void
    {
        $this->getJson('/api/v1/admin/integrations')->assertUnauthorized();

        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/integrations')->assertForbidden();
        }
    }

    public function test_email_must_really_send_outside_local(): void
    {
        $this->configureServices(['mail.default' => 'log']);
        $mail = $this->services()['mail'];
        $this->assertSame('bad', $mail['status']);
        $this->assertStringContainsString('tidak benar-benar terkirim', $mail['issues'][0]);

        $this->configureServices(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1']);
        $this->assertStringContainsString('MAIL_HOST', $this->services()['mail']['issues'][0]);

        $this->configureServices(['services.resend.key' => null]);
        $this->assertStringContainsString('Kunci API resend', $this->services()['mail']['issues'][0]);

        $this->configureServices(['mail.from.address' => 'hello@example.com']);
        $this->assertSame('warn', $this->services()['mail']['status']);

        $this->app->detectEnvironment(fn () => 'local');
        $this->configureServices(['mail.default' => 'log']);
        $mail = $this->services()['mail'];
        $this->assertSame('ok', $mail['status']);
        $this->assertStringContainsString('ditulis ke log', $mail['note']);
    }

    public function test_whatsapp_otp_is_only_a_problem_when_required_and_incomplete(): void
    {
        $this->assertSame('warn', $this->services()['whatsapp']['status']);

        $this->configureServices(['services.whatsapp.otp_required' => true]);
        $this->assertSame('bad', $this->services()['whatsapp']['status']);

        $this->configureServices(['services.whatsapp.token' => 'wa-token', 'services.whatsapp.phone_number_id' => '123', 'services.whatsapp.otp_template' => 'otp', 'services.whatsapp.otp_required' => false]);
        $whatsapp = $this->services()['whatsapp'];
        $this->assertSame('ok', $whatsapp['status']);
        $this->assertStringContainsString('belum diwajibkan', $whatsapp['note']);

        $this->app->detectEnvironment(fn () => 'local');
        $this->configureServices();
        $this->assertSame('ok', $this->services()['whatsapp']['status']);
    }

    public function test_turnstile_secret_is_required_outside_local_and_skipped_in_local(): void
    {
        $this->configureServices(['services.turnstile.secret' => null]);
        $this->assertSame('bad', $this->services()['turnstile']['status']);

        $this->configureServices(['services.turnstile.site_key' => null]);
        $this->assertSame('warn', $this->services()['turnstile']['status']);

        $this->app->detectEnvironment(fn () => 'local');
        $this->configureServices(['services.turnstile.secret' => null, 'services.turnstile.site_key' => null]);
        $this->assertSame('ok', $this->services()['turnstile']['status']);
    }

    public function test_claude_is_optional_and_reports_a_disabled_chat(): void
    {
        $claude = $this->services()['claude'];
        $this->assertSame('warn', $claude['status']);
        $this->assertStringContainsString('nonaktif', $claude['issues'][0]);

        $this->configureServices(['services.anthropic.key' => 'sk-ant-rahasia', 'services.anthropic.chat_enabled' => false]);
        $claude = $this->services()['claude'];
        $this->assertSame('ok', $claude['status']);
        $this->assertStringContainsString('CHAT_AI_ENABLED=false', $claude['note']);
    }

    public function test_dashboard_summarises_other_services_in_one_row_and_raises_the_marker(): void
    {
        // WhatsApp dan Claude yang belum dikonfigurasi hanya peringatan, bukan masalah.
        $this->assertSame('Periksa', $this->rows()['Layanan lain']['chip']['text']);

        $this->configureServices(['mail.default' => 'log', 'services.turnstile.secret' => null]);

        $row = $this->rows()['Layanan lain'];
        $this->assertSame('Bermasalah', $row['chip']['text']);
        $this->assertStringContainsString('Email:', $row['subtitle']);
        $this->assertStringContainsString('Turnstile:', $row['subtitle']);
        $this->assertContains('integration_issue', array_column($this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/dashboard')->json('data.attention'), 'key'));
    }

    public function test_live_check_reads_the_other_services_and_never_leaks_their_secrets(): void
    {
        $this->configureServices([
            'services.whatsapp.token' => 'wa-token-rahasia', 'services.whatsapp.phone_number_id' => '123456', 'services.whatsapp.otp_template' => 'otp',
            'services.anthropic.key' => 'sk-ant-kunci-rahasia',
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token wa-token-rahasia']], 400),
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']]),
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key sk-ant-kunci-rahasia']], 401),
            'api.sandbox.midtrans.com/*' => Http::response(['status_code' => '404'], 404),
            'api.biteship.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/integrations/check')->assertOk();

        $this->assertSame('skipped', $response->json('data.mail.status'));
        $this->assertSame('bad', $response->json('data.whatsapp.status'));
        $this->assertSame('bad', $response->json('data.turnstile.status'));
        $this->assertSame('bad', $response->json('data.claude.status'));
        foreach (['wa-token-rahasia', 'ts-rahasia-turnstile', 'sk-ant-kunci-rahasia', 're_kunci-rahasia-email'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $services = $this->services();
        $this->assertSame('bad', $services['whatsapp']['status']);
        $this->assertSame('bad', $services['turnstile']['status']);
        $this->assertSame('bad', $services['claude']['status']);
        $this->assertStringNotContainsString('rahasia', json_encode($services));
    }

    public function test_live_check_accepts_valid_other_services(): void
    {
        $this->configureServices([
            'services.whatsapp.token' => 'wa-token', 'services.whatsapp.phone_number_id' => '123456', 'services.whatsapp.otp_template' => 'otp',
            'services.anthropic.key' => 'sk-ant-kunci',
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '123456']),
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
            'api.anthropic.com/*' => Http::response(['id' => 'claude-haiku-5-5']),
            'api.sandbox.midtrans.com/*' => Http::response(['status_code' => '404'], 404),
            'api.biteship.com/*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/integrations/check')->assertOk();

        $services = $this->services();
        foreach (['whatsapp', 'turnstile', 'claude'] as $key) {
            $this->assertSame('ok', $services[$key]['status'], $key);
        }
    }

    public function test_dashboard_never_calls_the_network(): void
    {
        Http::fake();

        $this->rows();

        Http::assertNothingSent();
    }

    public function test_only_super_admin_can_run_the_live_check(): void
    {
        Http::fake();

        $this->postJson('/api/v1/admin/integrations/check')->assertUnauthorized();

        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->postJson('/api/v1/admin/integrations/check')->assertForbidden();
        }

        Http::assertNothingSent();
    }
}
