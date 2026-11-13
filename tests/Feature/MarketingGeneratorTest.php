<?php

namespace Tests\Feature;

use App\Models\MarketingDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketing\MarketingModel;
use App\Services\Marketing\MarketingModelException;
use App\Services\MarketingGenerator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pembuat draf konten dengan Claude (D-079): hanya draf, tanpa data pelanggan, dengan rem biaya.
 */
class MarketingGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/marketing-drafts/generate';

    private FakeMarketingModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config(['services.anthropic.key' => 'test-key', 'services.anthropic.marketing_enabled' => true]);
        $this->model = new FakeMarketingModel;
        $this->app->instance(MarketingModel::class, $this->model);

        Product::create(['name' => 'Nastar Uji Premium', 'slug' => 'nastar-uji', 'is_published' => true]);
        Product::create(['name' => 'Produk Draf Rahasia', 'slug' => 'draf-rahasia', 'is_published' => false]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $override */
    private function request(array $override = []): array
    {
        return ['type' => 'caption', 'platform' => 'instagram', 'brief' => 'Caption Nastar untuk Natal, tema hadiah keluarga.', ...$override];
    }

    public function test_generates_a_plain_draft_for_marketing(): void
    {
        $response = $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request())->assertCreated();

        $response->assertJsonPath('data.status', 'draft')->assertJsonPath('data.title', 'Judul AI')->assertJsonPath('data.type', 'caption');
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_draft.generated']);
        $this->assertCount(1, $this->model->calls);
        $this->assertFalse($this->model->calls[0]['search']);
    }

    public function test_only_marketing_and_super_admin_may_generate(): void
    {
        foreach (['owner', 'store-admin', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->postJson(self::URL, $this->request())->assertForbidden();
        }
        $this->actingAs($this->staff('super-admin'))->postJson(self::URL, $this->request())->assertCreated();

        $this->assertCount(1, $this->model->calls);
    }

    public function test_guest_and_unenrolled_mfa_are_refused(): void
    {
        $this->postJson(self::URL, $this->request())->assertUnauthorized();

        $user = User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $user->assignRole('marketing');
        $this->actingAs($user)->postJson(self::URL, $this->request())->assertForbidden();
        $this->assertSame([], $this->model->calls);
    }

    public function test_model_only_receives_the_brief_and_published_product_names(): void
    {
        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request())->assertCreated();

        $prompt = $this->model->calls[0]['prompt'];
        $this->assertStringContainsString('Nastar Uji Premium', $prompt);
        $this->assertStringContainsString('<brief>Caption Nastar untuk Natal', $prompt);
        $this->assertStringNotContainsString('Produk Draf Rahasia', $prompt);
        $this->assertStringNotContainsString('@', $prompt);
    }

    public function test_brief_cannot_set_status_or_other_fields(): void
    {
        $this->model->text = json_encode(['title' => 'Judul', 'body' => 'Isi', 'status' => 'approved']);

        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request(['status' => 'approved']))->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->assertSame('draft', MarketingDraft::firstOrFail()->status);
    }

    public function test_trend_report_uses_search_and_stores_sources_and_period(): void
    {
        $this->model->sources = [['title' => 'Google Trends', 'url' => 'https://trends.google.com/x']];
        $this->model->text = 'Riset tren Natal

1. Hampers nastar
2. Macaron kemasan merah';

        $response = $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request(['type' => 'trend_report', 'platform' => null]))->assertCreated();

        $this->assertTrue($this->model->calls[0]['search']);
        $this->assertStringContainsString('https://trends.google.com/x', $response->json('data.sources'));
        $this->assertNotEmpty($response->json('data.source_period'));
        $this->assertSame('Riset tren Natal', $response->json('data.title'));
        $this->assertStringStartsWith('1. Hampers nastar', $response->json('data.body'));
        $this->assertStringContainsString('Format balasan: baris pertama adalah judul', $this->model->calls[0]['system']);
    }

    public function test_trend_report_without_sources_is_not_saved(): void
    {
        $this->model->sources = [];
        $this->model->text = 'Riset tren

Isi tanpa sumber';

        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request(['type' => 'trend_report']))->assertStatus(503);

        $this->assertSame(0, MarketingDraft::count());
    }

    public function test_trend_report_with_only_a_title_line_is_rejected(): void
    {
        $this->model->text = 'Hanya satu baris tanpa isi';

        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request(['type' => 'trend_report']))->assertStatus(503);

        $this->assertSame(0, MarketingDraft::count());
    }

    public function test_regular_drafts_still_ask_for_json(): void
    {
        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request())->assertCreated();

        $this->assertStringContainsString('Balas HANYA objek JSON', $this->model->calls[0]['system']);
        $this->assertStringNotContainsString('Format balasan: baris pertama', $this->model->calls[0]['system']);
    }

    public function test_bad_model_output_is_rejected_without_leaking_it(): void
    {
        $this->model->text = 'Ini bukan JSON rahasia-isi-model';

        $response = $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request())->assertStatus(503);

        $this->assertStringNotContainsString('rahasia-isi-model', $response->getContent());
        $this->assertSame(0, MarketingDraft::count());
    }

    public function test_model_failure_is_a_safe_503(): void
    {
        $this->model->failure = new MarketingModelException('Claude API gagal: RateLimitError');

        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request())->assertStatus(503)->assertJsonPath('message', 'Claude API gagal: RateLimitError');
        $this->assertSame(0, MarketingDraft::count());
    }

    public function test_oversized_output_is_truncated_to_the_column_limits(): void
    {
        $this->model->text = json_encode(['title' => str_repeat('T', 400), 'body' => str_repeat('B', 9000)]);

        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request())->assertCreated();

        $draft = MarketingDraft::firstOrFail();
        $this->assertSame(150, mb_strlen($draft->title));
        $this->assertSame(5000, mb_strlen($draft->body));
    }

    public function test_switch_off_and_missing_key_stop_generation(): void
    {
        $marketing = $this->staff('marketing');

        config(['services.anthropic.marketing_enabled' => false]);
        $this->actingAs($marketing)->postJson(self::URL, $this->request())->assertStatus(503);

        config(['services.anthropic.marketing_enabled' => true, 'services.anthropic.key' => '']);
        $this->actingAs($marketing)->postJson(self::URL, $this->request())->assertStatus(503);

        $this->assertSame([], $this->model->calls);
        $this->actingAs($marketing)->getJson('/api/v1/admin/marketing-drafts')->assertJsonPath('data.ai_enabled', false);
    }

    public function test_store_wide_daily_limit_stops_generation(): void
    {
        Cache::put('marketing-ai:'.now()->toDateString(), MarketingGenerator::DAILY_LIMIT, now()->endOfDay());

        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->request())->assertStatus(503);

        $this->assertSame([], $this->model->calls);
    }

    public function test_brief_is_validated(): void
    {
        $marketing = $this->staff('marketing');

        $this->actingAs($marketing)->postJson(self::URL, $this->request(['brief' => 'pendek']))->assertUnprocessable()->assertJsonValidationErrors('brief');
        $this->actingAs($marketing)->postJson(self::URL, $this->request(['brief' => str_repeat('x', 1001)]))->assertUnprocessable();
        $this->actingAs($marketing)->postJson(self::URL, $this->request(['type' => 'poster']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame([], $this->model->calls);
    }

    public function test_per_account_rate_limit_applies(): void
    {
        $marketing = $this->staff('marketing');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($marketing)->postJson(self::URL, $this->request())->assertCreated();
        }
        $this->actingAs($marketing)->postJson(self::URL, $this->request())->assertStatus(429);
    }
}

class FakeMarketingModel implements MarketingModel
{
    /** @var list<array{system: string, prompt: string, search: bool}> */
    public array $calls = [];

    public string $text = '{"title": "Judul AI", "body": "Isi caption AI"}';

    /** @var list<array{title: string, url: string}> */
    public array $sources = [['title' => 'Sumber', 'url' => 'https://example.com/a']];

    public ?MarketingModelException $failure = null;

    public function generate(string $system, string $prompt, bool $search): array
    {
        $this->calls[] = ['system' => $system, 'prompt' => $prompt, 'search' => $search];

        if ($this->failure) {
            throw $this->failure;
        }

        return ['text' => $this->text, 'sources' => $search ? $this->sources : []];
    }
}
