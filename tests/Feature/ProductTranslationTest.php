<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Translation\ProductTranslationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Terjemahan Inggris produk (D-065): otomatis lewat AI atau manual. Semua panggilan Claude dipalsukan.
 */
class ProductTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        config(['services.anthropic.key' => 'sk-test-rahasia', 'services.anthropic.workspace_id' => null]);
    }

    private function staff(string $role = 'store-admin'): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function product(array $product = []): Product
    {
        $model = Product::withoutEvents(fn () => Product::create(['name' => 'Nastar Uji', 'slug' => 'nastar-uji', 'description' => 'Renyah dan lumer.', 'is_published' => true, ...$product]));
        $model->variants()->create(['sku' => 'NAS-UJI-01', 'name' => 'Isi 500 g', 'price' => 123000, 'weight_gram' => 500]);

        return $model;
    }

    /** Claude membalas JSON terjemahan untuk kunci yang diminta. */
    private function fakeClaude(?array $override = null): void
    {
        Http::fake(['api.anthropic.com/*' => function (Request $request) use ($override) {
            $keys = array_keys(json_decode($request['messages'][0]['content'], true));
            $out = $override ?? array_combine($keys, array_map(fn ($key) => "EN {$key}", $keys));

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode($out)]]]);
        }]);
    }

    public function test_creating_a_product_translates_it_automatically_with_the_configured_model(): void
    {
        $this->fakeClaude();

        $id = $this->actingAs($this->staff())->postJson('/api/v1/admin/products', ['name' => 'Nastar Baru', 'description' => 'Enak sekali.'])
            ->assertCreated()->json('data.id');

        $product = Product::findOrFail($id);
        $this->assertSame('EN name', $product->name_en);
        $this->assertSame('EN description', $product->description_en);
        $this->assertSame('ai', $product->translation_mode);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->header('x-api-key') === ['sk-test-rahasia']
            && $request['model'] === 'claude-haiku-5-5'
            && ! isset($request['temperature'], $request['top_p'], $request['top_k']));
    }

    public function test_changing_the_indonesian_text_retranslates_but_an_unchanged_save_does_not(): void
    {
        $this->fakeClaude();
        $admin = $this->staff();
        $product = $this->product();
        $service = app(ProductTranslationService::class);
        $service->apply($product->refresh());
        Http::assertSentCount(1);

        $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$product->id}", ['badges' => ['Baru']])->assertOk();
        Http::assertSentCount(1);

        $this->patchJson("/api/v1/admin/products/{$product->id}", ['description' => 'Deskripsi baru.'])->assertOk();
        Http::assertSentCount(2);
        $this->assertFalse($service->isOutdated($product->refresh()));
    }

    public function test_variant_names_are_translated_too(): void
    {
        $this->fakeClaude();
        $product = $this->product();
        app(ProductTranslationService::class)->apply($product->refresh());

        $this->assertSame('EN variant.'.$product->variants->first()->id, $product->variants->first()->fresh()->name_en);
    }

    public function test_save_succeeds_and_store_falls_back_to_indonesian_when_claude_fails(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);
        $product = $this->product();

        $this->actingAs($this->staff())->patchJson("/api/v1/admin/products/{$product->id}", ['name' => 'Nastar Uji 2'])->assertOk();

        $this->assertNull($product->fresh()->name_en);
        $this->get('/produk/nas-uji-01?lang=en')->assertOk()->assertSee('Nastar Uji 2');
    }

    public function test_no_request_is_made_without_an_api_key(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        $this->actingAs($this->staff())->postJson('/api/v1/admin/products', ['name' => 'Tanpa Kunci'])->assertCreated();

        Http::assertNothingSent();
    }

    public function test_typing_the_english_text_switches_to_manual_and_ai_never_overwrites_it(): void
    {
        $this->fakeClaude();
        $admin = $this->staff();
        $product = $this->product();

        $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$product->id}", ['name_en' => 'Test Nastar', 'description_en' => 'Crisp and melting.'])
            ->assertOk()->assertJsonPath('data.translation_mode', 'manual')->assertJsonPath('data.translation_outdated', false);

        $this->patchJson("/api/v1/admin/products/{$product->id}", ['name' => 'Nastar Uji Baru'])
            ->assertOk()->assertJsonPath('data.name_en', 'Test Nastar')->assertJsonPath('data.translation_outdated', true);
        Http::assertNothingSent();
    }

    public function test_returning_to_ai_mode_retranslates(): void
    {
        $this->fakeClaude();
        $admin = $this->staff();
        $product = $this->product(['name_en' => 'Tulisan tangan', 'translation_mode' => 'manual']);

        $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$product->id}", ['translation_mode' => 'ai'])
            ->assertOk()->assertJsonPath('data.translation_mode', 'ai');

        // Antrean memakai driver sync di tes; di produksi terjemahan masuk beberapa detik setelah respons.
        $this->assertSame('EN name', $product->fresh()->name_en);
    }

    public function test_editing_a_variant_english_name_makes_the_product_manual(): void
    {
        $this->fakeClaude();
        $product = $this->product();
        $variant = $product->variants->first();

        $this->actingAs($this->staff())->patchJson("/api/v1/admin/products/{$product->id}", ['variant_names_en' => [$variant->id => '500 g pack', 99999 => 'bukan milik produk ini']])
            ->assertOk()->assertJsonPath('data.translation_mode', 'manual')->assertJsonPath('data.variants.0.name_en', '500 g pack');

        Http::assertNothingSent();
        $this->assertSame('500 g pack', $variant->fresh()->name_en);
    }

    public function test_suggest_endpoint_returns_a_draft_without_saving(): void
    {
        $this->fakeClaude();
        $product = $this->product(['translation_mode' => 'manual']);
        $variantId = $product->variants->first()->id;

        $this->actingAs($this->staff())->postJson("/api/v1/admin/products/{$product->id}/translate")
            ->assertOk()
            ->assertJsonPath('data.name_en', 'EN name')
            ->assertJsonPath('data.description_en', 'EN description')
            ->assertJsonPath("data.variants.{$variantId}", "EN variant.{$variantId}");

        $this->assertNull($product->fresh()->name_en);
    }

    public function test_suggest_endpoint_reports_a_friendly_error_when_claude_fails_and_leaks_nothing(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('garbage', 500)]);
        $product = $this->product();

        $response = $this->actingAs($this->staff())->postJson("/api/v1/admin/products/{$product->id}/translate")->assertStatus(503);

        $this->assertStringNotContainsString('sk-test-rahasia', $response->getContent());
    }

    public function test_a_reply_that_is_not_the_expected_json_is_rejected(): void
    {
        $this->fakeClaude(['name' => 'hanya satu kunci']);
        $product = $this->product();

        $this->actingAs($this->staff())->postJson("/api/v1/admin/products/{$product->id}/translate")->assertStatus(503);
    }

    /** @return array<string, array{string, int}> */
    public static function roleAccess(): array
    {
        return [
            'admin toko boleh' => ['store-admin', 200],
            'marketing tidak' => ['marketing', 403],
            'gudang tidak' => ['warehouse', 403],
        ];
    }

    #[DataProvider('roleAccess')]
    public function test_only_roles_that_manage_products_can_ask_for_a_translation(string $role, int $status): void
    {
        $this->fakeClaude();
        $product = $this->product();

        $this->actingAs($this->staff($role))->postJson("/api/v1/admin/products/{$product->id}/translate")->assertStatus($status);
    }

    public function test_english_storefront_uses_the_saved_english_texts_and_indonesian_keeps_its_own(): void
    {
        $product = $this->product(['name_en' => 'Pineapple Nastar', 'description_en' => 'Crisp and buttery.']);
        $product->variants()->create(['sku' => 'NAS-UJI-02', 'name' => 'Isi 250 g', 'price' => 70000, 'weight_gram' => 250])->forceFill(['name_en' => '250 g pack'])->save();

        $this->get('/produk/nas-uji-01?lang=en')->assertOk()->assertSee('Pineapple Nastar Isi 500 g')->assertSee('Crisp and buttery.');
        $this->get('/produk/nas-uji-02?lang=en')->assertOk()->assertSee('Pineapple Nastar 250 g pack');
        $this->get('/produk/nas-uji-01?lang=id')->assertOk()->assertSee('Nastar Uji Isi 500 g')->assertSee('Renyah dan lumer.')->assertDontSee('Crisp and buttery.');
    }
}
