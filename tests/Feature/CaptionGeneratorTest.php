<?php

namespace Tests\Feature;

use App\Models\CaptionGeneration;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketing\MarketingModel;
use App\Services\MarketingGenerator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Generator Caption: tim marketing kirim isi video atau prompt, AI balas tiga variasi caption untuk disalin. */
class CaptionGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/caption-generations';

    private object $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config(['services.anthropic.key' => 'test-key', 'services.anthropic.marketing_enabled' => true]);
        $this->model = new class implements MarketingModel
        {
            public array $calls = [];

            public string $text = '{"captions": ["Natal tanpa ribet. #tufferobakery", "Hadiah manis untuk keluarga. #nastar", "  Pilih Nastar Sultan, ya Kak.  "]}';

            public function generate(string $system, string $prompt, bool $search, array $images = []): array
            {
                $this->calls[] = compact('system', 'prompt', 'search');

                return ['text' => $this->text, 'sources' => []];
            }
        };
        $this->app->instance(MarketingModel::class, $this->model);

        Product::create(['name' => 'Nastar Uji Premium', 'slug' => 'nastar-uji', 'is_published' => true]);
        Product::create(['name' => 'Produk Draf Rahasia', 'slug' => 'draf-rahasia', 'is_published' => false]);
    }

    private function staff(string $role = 'marketing'): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $override */
    private function body(array $override = []): array
    {
        return ['platform' => 'instagram', 'tone' => 'hangat', 'brief' => 'Video unboxing hampers Natal berisi Nastar dan Macaron.', ...$override];
    }

    public function test_marketing_gets_three_captions_and_the_history_is_saved(): void
    {
        $marketing = $this->staff();

        $this->actingAs($marketing)->postJson(self::URL, $this->body())->assertCreated()
            ->assertJsonCount(3, 'data.captions')
            ->assertJsonPath('data.captions.2', 'Pilih Nastar Sultan, ya Kak.')
            ->assertJsonPath('data.author', $marketing->name);

        $this->assertSame(1, CaptionGeneration::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'caption.generated']);
        $this->actingAs($marketing)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.ai_enabled', true);
    }

    public function test_the_model_gets_only_the_brief_and_published_product_names(): void
    {
        $this->actingAs($this->staff())->postJson(self::URL, $this->body())->assertCreated();

        $call = $this->model->calls[0];
        $this->assertFalse($call['search']);
        $this->assertStringContainsString('Nastar Uji Premium', $call['prompt']);
        $this->assertStringNotContainsString('Produk Draf Rahasia', $call['prompt']);
        $this->assertStringContainsString('<brief>Video unboxing hampers Natal', $call['prompt']);
        $this->assertStringContainsString('Gaya bahasa: Hangat', $call['prompt']);
    }

    public function test_only_marketing_and_super_admin_can_use_it(): void
    {
        foreach (['owner', 'store-admin', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->getJson(self::URL)->assertForbidden();
            $this->actingAs($this->staff($role))->postJson(self::URL, $this->body())->assertForbidden();
        }

        $this->actingAs($this->staff('super-admin'))->postJson(self::URL, $this->body())->assertCreated();
        $this->assertSame(1, count($this->model->calls));
    }

    public function test_input_is_validated(): void
    {
        $marketing = $this->staff();

        $this->actingAs($marketing)->postJson(self::URL, $this->body(['brief' => 'pendek']))->assertUnprocessable()->assertJsonValidationErrors('brief');
        $this->actingAs($marketing)->postJson(self::URL, $this->body(['platform' => 'facebook']))->assertUnprocessable()->assertJsonValidationErrors('platform');
        $this->actingAs($marketing)->postJson(self::URL, $this->body(['tone' => 'marah']))->assertUnprocessable()->assertJsonValidationErrors('tone');
        $this->actingAs($marketing)->postJson(self::URL, $this->body(['brief' => str_repeat('a', 2001)]))->assertUnprocessable();
        $this->assertSame([], $this->model->calls);
    }

    public function test_a_broken_answer_is_a_clean_error_and_nothing_is_saved(): void
    {
        $this->model->text = 'bukan json';

        $this->actingAs($this->staff())->postJson(self::URL, $this->body())->assertStatus(503);

        $this->assertSame(0, CaptionGeneration::count());
    }

    public function test_it_is_refused_when_ai_is_off_or_the_daily_limit_is_reached(): void
    {
        $marketing = $this->staff();

        Cache::put('marketing-ai:'.now()->toDateString(), MarketingGenerator::DAILY_LIMIT, now()->endOfDay());
        $this->actingAs($marketing)->postJson(self::URL, $this->body())->assertStatus(503);

        config(['services.anthropic.marketing_enabled' => false]);
        $this->actingAs($marketing)->postJson(self::URL, $this->body())->assertStatus(503);
        $this->assertSame([], $this->model->calls);
    }

    public function test_history_can_be_deleted_and_guests_are_rejected(): void
    {
        $marketing = $this->staff();
        $id = $this->actingAs($marketing)->postJson(self::URL, $this->body())->json('data.id');

        $this->actingAs($marketing)->deleteJson(self::URL."/{$id}")->assertOk();
        $this->assertSame(0, CaptionGeneration::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'caption.deleted']);
        $this->actingAs($this->staff('owner'))->deleteJson(self::URL.'/'.CaptionGeneration::create(['platform' => 'tiktok', 'brief' => 'x', 'captions' => ['a']])->public_id)->assertForbidden();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, $this->body())->assertUnauthorized();
    }
}
