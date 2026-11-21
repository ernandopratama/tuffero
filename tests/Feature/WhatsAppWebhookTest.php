<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Webhook WhatsApp Cloud API: tantangan verifikasi dan tanda tangan X-Hub-Signature-256.
 */
class WhatsAppWebhookTest extends TestCase
{
    private const URL = '/api/v1/webhooks/whatsapp';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.webhook_verify_token' => 'verify-me', 'services.whatsapp.app_secret' => 'app-secret']);
    }

    /** @param array<string, mixed> $payload */
    private function signedPost(array $payload, string $secret = 'app-secret')
    {
        $body = json_encode($payload);

        return $this->call('POST', self::URL, [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            'Content-Type' => 'application/json',
        ]), $body);
    }

    public function test_it_answers_the_meta_challenge_with_the_right_token(): void
    {
        $this->get(self::URL.'?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')
            ->assertOk()->assertSee('12345', false);
    }

    public function test_it_rejects_a_wrong_or_unset_verify_token(): void
    {
        $this->get(self::URL.'?hub_mode=subscribe&hub_verify_token=salah&hub_challenge=1')->assertForbidden();

        config(['services.whatsapp.webhook_verify_token' => null]);
        $this->get(self::URL.'?hub_mode=subscribe&hub_verify_token=&hub_challenge=1')->assertForbidden();
    }

    public function test_it_logs_failed_deliveries_without_the_phone_number(): void
    {
        Log::spy();

        $this->signedPost(['entry' => [['changes' => [['value' => ['statuses' => [
            ['id' => 'wamid.1', 'status' => 'failed', 'recipient_id' => '6281234567890', 'errors' => [['code' => 131026]]],
        ]]]]]]])->assertOk();

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn ($message, $context) => $context['message_id'] === 'wamid.1' && ! str_contains(json_encode($context), '6281234567890'));
    }

    public function test_it_rejects_a_bad_signature_or_an_unset_app_secret(): void
    {
        $this->signedPost(['entry' => []], 'bukan-rahasia')->assertForbidden();

        config(['services.whatsapp.app_secret' => null]);
        $this->signedPost(['entry' => []])->assertForbidden();
    }
}
