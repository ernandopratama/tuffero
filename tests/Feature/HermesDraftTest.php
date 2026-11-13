<?php

namespace Tests\Feature;

use App\Models\MarketingDraft;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Penerima laporan riset dari Hermes (D-079): token bersama, hanya menambah draf, idempoten.
 */
class HermesDraftTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/integrations/hermes/drafts';

    private const TOKEN = 'hermes-test-token-hermes-test-token-1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.hermes.token' => self::TOKEN]);
    }

    /** @param array<string, mixed> $override */
    private function report(array $override = []): array
    {
        return [
            'external_id' => 'bed7c0ad3a34:2026-11-16_08-00-30',
            'type' => 'trend_report',
            'title' => 'Riset tren mingguan 16 Nov 2026',
            'body' => "5 ide tren\n1. Nastar hampers Natal",
            'sources' => 'https://trends.google.com/trends/explore',
            'source_period' => '9 Nov 2026 sampai 16 Nov 2026',
            ...$override,
        ];
    }

    private function send(array $payload, ?string $token = self::TOKEN): TestResponse
    {
        return $this->withHeaders($token === null ? [] : ['Authorization' => 'Bearer '.$token])->postJson(self::URL, $payload);
    }

    public function test_accepts_a_report_as_a_hermes_draft(): void
    {
        $this->send($this->report())->assertCreated()->assertJsonStructure(['data' => ['id']]);

        $draft = MarketingDraft::firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('hermes', $draft->origin);
        $this->assertNull($draft->created_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_draft.created', 'actor_user_id' => null]);
    }

    public function test_wrong_missing_or_short_token_is_refused(): void
    {
        $this->send($this->report(), 'salah-salah-salah-salah-salah-salah-1')->assertUnauthorized();
        $this->send($this->report(), null)->assertUnauthorized();
        $this->assertSame(0, MarketingDraft::count());

        // Token terlalu pendek atau kosong = penerima mati, berpura-pura tidak ada.
        config(['services.hermes.token' => 'pendek']);
        $this->send($this->report(), 'pendek')->assertNotFound();
        config(['services.hermes.token' => null]);
        $this->send($this->report(), '')->assertNotFound();
        $this->assertSame(0, MarketingDraft::count());
    }

    public function test_a_staff_session_cannot_use_it_without_the_token(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('super-admin');

        $this->actingAs($owner)->postJson(self::URL, $this->report())->assertUnauthorized();
    }

    public function test_resending_the_same_report_does_not_duplicate(): void
    {
        $first = $this->send($this->report())->assertCreated()->json('data.id');
        $second = $this->send($this->report(['title' => 'Judul berbeda']))->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, MarketingDraft::count());
        $this->assertSame('Riset tren mingguan 16 Nov 2026', MarketingDraft::firstOrFail()->title);
    }

    public function test_trend_report_needs_sources_and_period(): void
    {
        $this->send($this->report(['sources' => null, 'source_period' => null]))->assertUnprocessable()->assertJsonValidationErrors(['sources', 'source_period']);
        $this->assertSame(0, MarketingDraft::count());
    }

    public function test_cannot_set_status_origin_or_creator(): void
    {
        $this->send($this->report(['status' => 'approved', 'origin' => 'manual', 'created_by_user_id' => 1, 'posted_at' => now()->toDateTimeString()]))->assertCreated();

        $draft = MarketingDraft::firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('hermes', $draft->origin);
        $this->assertNull($draft->created_by_user_id);
        $this->assertNull($draft->posted_at);
    }

    public function test_input_is_validated(): void
    {
        $this->send($this->report(['type' => 'poster']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->send($this->report(['external_id' => 'ada spasi/dan<tag>']))->assertUnprocessable()->assertJsonValidationErrors('external_id');
        $this->send($this->report(['body' => str_repeat('x', 5001)]))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->send($this->report(['platform' => 'facebook']))->assertUnprocessable();
        $this->assertSame(0, MarketingDraft::count());
    }

    public function test_script_text_is_stored_as_plain_text(): void
    {
        $this->send($this->report(['body' => '<script>alert(1)</script> isi']))->assertCreated();

        $this->assertSame('<script>alert(1)</script> isi', MarketingDraft::firstOrFail()->body);
    }

    public function test_marketing_still_reviews_a_hermes_draft_like_any_other(): void
    {
        $this->send($this->report())->assertCreated();
        $draft = MarketingDraft::firstOrFail();

        $marketing = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $marketing->assignRole('marketing');

        $this->actingAs($marketing)->getJson('/api/v1/admin/marketing-drafts')->assertOk()->assertJsonPath('data.items.0.origin', 'hermes');
        $this->actingAs($marketing)->postJson("/api/v1/admin/marketing-drafts/{$draft->id}/move", ['action' => 'submit'])->assertOk()->assertJsonPath('data.status', 'in_review');
    }

    public function test_rate_limit_applies(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->send($this->report(['external_id' => "job:{$i}"]))->assertCreated();
        }

        $this->send($this->report(['external_id' => 'job:11']))->assertStatus(429);
    }

    public function test_requests_without_the_token_do_not_use_up_the_quota(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->send($this->report(), 'salah-salah-salah-salah-salah-salah-1')->assertUnauthorized();
        }

        $this->send($this->report())->assertCreated();
    }
}
