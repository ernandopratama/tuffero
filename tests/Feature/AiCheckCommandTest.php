<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * php artisan ai:check: melapor tanpa pernah mencetak kunci. Semua panggilan HTTP dipalsukan.
 */
class AiCheckCommandTest extends TestCase
{
    private const KEY = 'sk-test-rahasia-jangan-tampil';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => self::KEY,
            'services.anthropic.workspace_id' => null,
            'services.anthropic.models' => ['translation' => 'claude-haiku-5-5', 'product_intake' => 'claude-haiku-5-5'],
        ]);
    }

    public function test_succeeds_when_every_model_is_available_and_sends_the_expected_headers(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'claude-haiku-5-5'])]);

        $this->artisan('ai:check')
            ->expectsOutputToContain('translation: OK, model claude-haiku-5-5 tersedia.')
            ->expectsOutputToContain('product_intake: OK')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/models/claude-haiku-5-5'
            && $request->header('x-api-key') === [self::KEY]
            && $request->header('anthropic-version') === ['2026-10-21']
            && ! $request->hasHeader('anthropic-workspace-id'));
    }

    public function test_sends_the_workspace_header_when_configured(): void
    {
        config(['services.anthropic.workspace_id' => 'wrkspc_test']);
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'x'])]);

        $this->artisan('ai:check')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->header('anthropic-workspace-id') === ['wrkspc_test']);
    }

    public function test_fails_without_a_key_and_makes_no_request(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        $this->artisan('ai:check')->expectsOutputToContain('ANTHROPIC_API_KEY belum diisi')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_reports_a_rejected_key_and_an_unknown_model_without_leaking_the_key(): void
    {
        config(['services.anthropic.models' => ['translation' => 'claude-haiku-5-5', 'product_intake' => 'model-salah']]);
        Http::fake([
            'api.anthropic.com/v1/models/model-salah' => Http::response(['error' => ['message' => 'not found']], 404),
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401),
        ]);

        $this->artisan('ai:check')
            ->expectsOutputToContain('translation: GAGAL, kunci ditolak')
            ->expectsOutputToContain('product_intake: GAGAL, model model-salah tidak ditemukan')
            ->doesntExpectOutputToContain(self::KEY)
            ->assertFailed();
    }

    public function test_reports_a_connection_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->artisan('ai:check')->expectsOutputToContain('tidak bisa menghubungi Claude API')->doesntExpectOutputToContain(self::KEY)->assertFailed();
    }
}
