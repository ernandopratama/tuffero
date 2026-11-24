<?php

namespace Tests\Feature;

use App\Models\MarketingDraft;
use App\Models\MarketingDraftMetric;
use App\Models\User;
use App\Services\MarketingDraftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Endpoint baca metrik untuk Hermes (D-150): token baca khusus, hanya baca, tanpa data sensitif. */
class HermesMetricTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/integrations/hermes/metrics';

    private const READ = 'hermes-read-token-hermes-read-token-123456';

    private const INGEST = 'hermes-test-token-hermes-test-token-1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        config(['services.hermes.read_token' => self::READ, 'services.hermes.token' => self::INGEST]);
    }

    private function metric(string $title = 'Nastar Natal', string $day = '2026-11-20', int $views = 1000): MarketingDraftMetric
    {
        $draft = app(MarketingDraftService::class)->create(['type' => 'video_script', 'title' => $title, 'body' => 'Isi rahasia'], null, MarketingDraft::MANUAL);
        $draft->forceFill(['status' => MarketingDraft::POSTED])->save();

        $metric = new MarketingDraftMetric(['platform' => 'tiktok', 'post_url' => 'https://www.tiktok.com/@tuffero/video/1', 'measured_on' => $day, 'views' => $views, 'likes' => 10, 'shares' => 2, 'comments' => 3, 'saves' => 4, 'reach' => null, 'new_followers' => 7]);
        $metric->marketing_draft_id = $draft->id;
        $metric->save();

        return $metric;
    }

    private function readMetrics(string $query = '', ?string $token = self::READ)
    {
        return $this->withHeaders($token === null ? [] : ['Authorization' => 'Bearer '.$token])->getJson(self::URL.$query);
    }

    public function test_read_token_returns_flat_rows_without_sensitive_fields(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 23));
        $this->metric();

        $row = $this->readMetrics()->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame(['draft_id', 'number', 'title', 'platform', 'measured_on', 'views', 'likes', 'shares', 'comments', 'saves', 'reach', 'new_followers'], array_keys($row));
        $this->assertSame(26, strlen($row['draft_id']));
        $this->assertSame(1000, $row['views']);
        $this->assertNull($row['reach']);
        $this->assertStringNotContainsString('rahasia', json_encode($row));
        $this->assertStringNotContainsString('tiktok.com', json_encode($row));
    }

    public function test_token_is_required_and_separate_from_the_ingest_token(): void
    {
        $this->metric();

        $this->readMetrics('', null)->assertUnauthorized();
        $this->readMetrics('', 'salah-salah-salah-salah-salah-salah-123')->assertUnauthorized();
        // Token kiriman draf tidak bisa membaca metrik.
        $this->readMetrics('', self::INGEST)->assertUnauthorized();
        // Token baca tidak bisa mengirim draf.
        $this->withHeader('Authorization', 'Bearer '.self::READ)->postJson('/api/v1/integrations/hermes/drafts', ['type' => 'trend_report'])->assertUnauthorized();
    }

    public function test_endpoint_is_hidden_when_read_token_is_missing_or_short_and_logged_in_staff_are_not_enough(): void
    {
        config(['services.hermes.read_token' => null]);
        $this->readMetrics()->assertNotFound();
        config(['services.hermes.read_token' => 'pendek']);
        $this->readMetrics('', 'pendek')->assertNotFound();

        config(['services.hermes.read_token' => self::READ]);
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole('super-admin');
        $this->actingAs($user)->getJson(self::URL)->assertUnauthorized();
    }

    public function test_days_window_filters_old_rows_and_is_validated(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 23));
        $this->metric('Baru', '2026-11-20');
        $this->metric('Lama', '2026-06-01');

        $this->readMetrics()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Baru');
        $this->readMetrics('?days=365')->assertOk()->assertJsonCount(2, 'data');
        $this->readMetrics('?days=0')->assertUnprocessable();
        $this->readMetrics('?days=366')->assertUnprocessable();
        $this->readMetrics('?days=abc')->assertUnprocessable();
    }

    public function test_deleted_drafts_are_excluded_and_endpoint_is_read_only(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 23));
        $this->metric('Dihapus')->draft->delete();

        $this->readMetrics()->assertOk()->assertJsonCount(0, 'data');
        $this->withHeader('Authorization', 'Bearer '.self::READ)->postJson(self::URL, [])->assertStatus(405);
        $this->withHeader('Authorization', 'Bearer '.self::READ)->deleteJson(self::URL)->assertStatus(405);
    }
}
