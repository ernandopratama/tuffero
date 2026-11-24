<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\SeoHealthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Halaman SEO dashboard (izin reports.view: Super Admin dan Pemilik): kelengkapan konten, pemeriksaan teknis,
 * pratinjau halaman, dan checklist peluncuran. Semua panggilan keluar dipalsukan; tidak ada jaringan sungguhan.
 */
class SeoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tuffero.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['app.url' => self::BASE]);
        Cache::forget(SeoHealthService::CACHE_KEY);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** Produk tayang di kategori nastar; `$complete` mengisi semua butir kelengkapan. */
    private function product(string $name, string $sku, bool $complete = false): Product
    {
        $category = ProductCategory::firstOrCreate(['slug' => 'nastar'], ['name_id' => 'Nastar', 'name_en' => 'Nastar', 'is_active' => true]);
        $product = Product::create([
            'name' => $name,
            'slug' => strtolower($sku),
            'is_published' => true,
            'product_category_id' => $category->id,
            'description' => $complete ? 'Nastar nanas berisi selai buatan sendiri.' : null,
            'description_en' => $complete ? 'Pineapple nastar with homemade jam.' : null,
        ]);
        $product->variants()->create([
            'sku' => $sku,
            'name' => 'Standar',
            'price' => 100000,
            'weight_gram' => 500,
            'shelf_life_days' => $complete ? 21 : null,
        ]);
        if ($complete) {
            foreach ([1, 2, 3] as $n) {
                $product->images()->create(['path' => "products/{$product->id}/{$n}.webp", 'alt' => $name, 'sort_order' => $n]);
            }
        }

        return $product;
    }

    private function html(string $title, string $description, string $extra = '', string $canonical = self::BASE): string
    {
        return '<html><head><title>'.$title.'</title><meta name="description" content="'.$description.'">'
            .'<link rel="canonical" href="'.$canonical.'"><meta property="og:image" content="'.self::BASE.'/images/a.webp">'.$extra
            .'<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Bakery"},{"@type":"WebSite"}]}</script>'
            .'</head><body><h1>Judul halaman</h1></body></html>';
    }

    /** Situs sehat: semua pemeriksaan teknis lulus. */
    private function fakeHealthySite(?string $homeHtml = null, array $override = []): void
    {
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.self::BASE.'/</loc></url><url><loc>'.self::BASE.'/produk</loc></url></urlset>';

        Http::fake(fn (Request $request) => match (true) {
            isset($override[$request->url()]) => $override[$request->url()],
            $request->url() === self::BASE.'/' => Http::response($homeHtml ?? $this->html('Tuffero Bakery Malang, Nastar dan Macaron Premium', 'Toko kue Tuffero Bakery di Malang: nastar, macaron, pia, dan cookies premium. Pesan online.')),
            $request->url() === self::BASE.'/robots.txt' => Http::response("User-agent: *\nDisallow: /admin\n\nSitemap: ".self::BASE.'/sitemap.xml'),
            $request->url() === self::BASE.'/sitemap.xml' => Http::response($sitemap),
            $request->url() === self::BASE.'/index.php' => Http::response('', 301),
            str_starts_with($request->url(), self::BASE.'/produk/') => Http::response('', 301),
            default => Http::response('tidak ada', 404),
        });
    }

    public function test_only_owner_and_super_admin_open_the_seo_pages(): void
    {
        foreach (['super-admin', 'owner'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/seo/overview')->assertOk()->assertJsonPath('data.google.connected', false);
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/seo/content')->assertOk();
        }

        foreach (['marketing', 'store-admin', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/seo/overview')->assertForbidden();
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/seo/content')->assertForbidden();
            $this->actingAs($this->staff($role))->postJson('/api/v1/admin/seo/technical/check')->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/seo/overview')->assertUnauthorized();
    }

    public function test_content_health_scores_each_product_and_lists_the_weakest_first(): void
    {
        $this->product('Nastar Lengkap', 'NAS-OK-01', complete: true);
        $weak = $this->product('Nastar Kosong', 'NAS-KOSONG-01');

        $response = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/seo/content')->assertOk();

        $response->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.ready', 1)
            ->assertJsonPath('data.summary.average_score', 60)
            ->assertJsonPath('data.products.0.name', 'Nastar Kosong')
            ->assertJsonPath('data.products.0.id', $weak->public_id)
            ->assertJsonPath('data.products.0.score', 20)
            ->assertJsonPath('data.products.1.name', 'Nastar Lengkap')
            ->assertJsonPath('data.products.1.score', 100)
            ->assertJsonPath('data.products.1.issues', []);
        $this->assertContains('Deskripsi Indonesia kosong', $response->json('data.products.0.issues'));
        $this->assertContains('Foto kurang dari 3', $response->json('data.products.0.issues'));
        $this->assertSame(1, collect($response->json('data.summary.checks'))->firstWhere('key', 'description_id')['done']);
        // Halaman yang boleh dipratinjau: statis, produk, dan kategori yang punya halaman.
        $paths = collect($response->json('data.pages'))->pluck('path');
        $this->assertTrue($paths->contains('/') && $paths->contains('/produk/nas-ok-01') && $paths->contains('/kategori/nastar'));
    }

    public function test_technical_check_passes_on_a_healthy_site_and_is_stored(): void
    {
        $this->product('Nastar Lengkap', 'NAS-OK-01', complete: true);
        $this->fakeHealthySite();
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->getJson('/api/v1/admin/seo/overview')->assertOk()->assertJsonPath('data.technical', null);

        // Lingkungan uji bukan produksi: situs tanpa noindex menghasilkan peringatan, sisanya lulus.
        $result = $this->actingAs($admin)->postJson('/api/v1/admin/seo/technical/check')->assertOk();
        $checks = collect($result->json('data.checks'))->keyBy('key');

        $this->assertSame(['https', 'home', 'indexable', 'canonical', 'robots', 'sitemap', 'duplicates', 'not_found'], $checks->keys()->all());
        foreach (['https', 'home', 'canonical', 'robots', 'sitemap', 'duplicates', 'not_found'] as $key) {
            $this->assertSame('ok', $checks[$key]['status'], $key.': '.$checks[$key]['message']);
        }
        $this->assertSame('warn', $checks['indexable']['status']);
        $this->assertStringContainsString('2 URL', $checks['sitemap']['message']);
        $result->assertJsonPath('data.status', 'warn')->assertJsonPath('data.base_url', self::BASE);

        $this->actingAs($admin)->getJson('/api/v1/admin/seo/overview')->assertOk()->assertJsonPath('data.technical.base_url', self::BASE);
    }

    public function test_technical_check_reports_production_problems(): void
    {
        $this->product('Nastar Lengkap', 'NAS-OK-01', complete: true);
        config(['app.env' => 'production', 'app.url' => 'http://tuffero.test']);

        // noindex di produksi, canonical ke host lain, sitemap berisi host lain, /index.php tidak dialihkan, 404 palsu (200).
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>http://tuffero.test/</loc></url><url><loc>https://lain.example/x</loc></url></urlset>';
        Http::fake(fn (Request $request) => match (true) {
            $request->url() === 'http://tuffero.test/' => Http::response($this->html('Tuffero', 'x', '<meta name="robots" content="noindex, nofollow">', 'https://lain.example/'), 200),
            $request->url() === 'http://tuffero.test/robots.txt' => Http::response("User-agent: *\nDisallow: /\n"),
            $request->url() === 'http://tuffero.test/sitemap.xml' => Http::response($sitemap),
            $request->url() === 'http://tuffero.test/index.php' => Http::response('halaman', 200),
            str_starts_with($request->url(), 'http://tuffero.test/produk/') => Http::response('', 301),
            default => Http::response('halaman biasa', 200),
        });

        $checks = collect($this->actingAs($this->staff('owner'))->postJson('/api/v1/admin/seo/technical/check')->assertOk()->json('data.checks'))->keyBy('key');

        $this->assertSame('bad', $checks['https']['status']);
        $this->assertSame('bad', $checks['indexable']['status']);
        $this->assertSame('bad', $checks['canonical']['status']);
        $this->assertSame('bad', $checks['robots']['status']);
        $this->assertSame('warn', $checks['sitemap']['status']);
        $this->assertStringContainsString('1 di antaranya', $checks['sitemap']['message']);
        $this->assertSame('warn', $checks['duplicates']['status']);
        $this->assertStringContainsString('/index.php menjawab 200', $checks['duplicates']['message']);
        $this->assertSame('bad', $checks['not_found']['status']);
    }

    public function test_a_broken_connection_marks_checks_instead_of_failing_the_request(): void
    {
        Http::fake(fn () => throw new ConnectionException('tidak tersambung'));

        $result = $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/seo/technical/check')->assertOk();
        $checks = collect($result->json('data.checks'))->keyBy('key');

        $this->assertSame('bad', $checks['home']['status']);
        $this->assertSame('unknown', $checks['indexable']['status']);
        $this->assertSame('unknown', $checks['canonical']['status']);
        $result->assertJsonPath('data.status', 'bad');
    }

    public function test_preview_reads_the_real_page_and_flags_snippet_problems(): void
    {
        $this->product('Nastar Lengkap', 'NAS-OK-01', complete: true);
        $long = str_repeat('a', 70);
        Http::fake([
            self::BASE.'/produk/nas-ok-01' => Http::response($this->html($long, 'pendek'), 200),
            self::BASE.'/produk/nas-ok-01?lang=en' => Http::response($this->html('English title long enough for a snippet here', str_repeat('d', 100)), 200),
        ]);
        $admin = $this->staff('owner');

        $id = $this->actingAs($admin)->getJson('/api/v1/admin/seo/preview?path=/produk/nas-ok-01')->assertOk();
        $id->assertJsonPath('data.title_length', 70)->assertJsonPath('data.description_length', 6)->assertJsonPath('data.indexable', true)
            ->assertJsonPath('data.h1', 'Judul halaman')->assertJsonPath('data.json_ld', ['Bakery', 'WebSite'])
            ->assertJsonPath('data.og_image', self::BASE.'/images/a.webp');
        $issues = implode(' ', $id->json('data.issues'));
        $this->assertStringContainsString('lebih dari 60', $issues);
        $this->assertStringContainsString('kurang dari 70', $issues);

        $en = $this->actingAs($admin)->getJson('/api/v1/admin/seo/preview?path=/produk/nas-ok-01&lang=en')->assertOk();
        $en->assertJsonPath('data.issues', []);
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/produk/nas-ok-01?lang=en');
    }

    public function test_preview_only_opens_pages_from_the_server_list(): void
    {
        Http::fake();
        $admin = $this->staff('super-admin');

        foreach (['/admin', '/api/v1/admin/users', 'https://evil.example/', '//evil.example', '/produk/tidak-ada', '../.env'] as $path) {
            $this->actingAs($admin)->getJson('/api/v1/admin/seo/preview?path='.urlencode($path))->assertStatus(422);
        }
        $this->actingAs($admin)->getJson('/api/v1/admin/seo/preview')->assertStatus(422);
        $this->actingAs($admin)->getJson('/api/v1/admin/seo/preview?path=/&lang=fr')->assertStatus(422);

        // Tidak satu pun permintaan keluar untuk jalur yang ditolak.
        Http::assertNothingSent();
    }

    public function test_preview_marks_a_page_that_cannot_be_opened(): void
    {
        Http::fake([self::BASE.'/kontak' => Http::response('rusak', 500)]);

        $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/seo/preview?path=/kontak')->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonPath('data.status', 500);
    }

    public function test_checklist_can_be_ticked_and_untied_and_is_audited(): void
    {
        $admin = $this->staff('owner');

        $this->actingAs($admin)->putJson('/api/v1/admin/seo/checklist/search-console', ['done' => true])->assertOk();

        $item = fn () => collect($this->actingAs($admin)->getJson('/api/v1/admin/seo/overview')->json('data.checklist'))->firstWhere('key', 'search-console');
        $this->assertTrue($item()['done']);
        $this->assertSame($admin->name, $item()['done_by']);
        $this->assertNotNull($item()['done_at']);
        $this->assertSame(1, AuditLog::where('action', 'seo.checklist_updated')->count());

        $this->actingAs($admin)->putJson('/api/v1/admin/seo/checklist/search-console', ['done' => false])->assertOk();
        $this->assertFalse($item()['done']);
        $this->assertSame(2, AuditLog::where('action', 'seo.checklist_updated')->count());
    }

    public function test_checklist_rejects_automatic_unknown_and_invalid_input(): void
    {
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->putJson('/api/v1/admin/seo/checklist/product-descriptions', ['done' => true])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/seo/checklist/tidak-ada', ['done' => true])->assertNotFound();
        $this->actingAs($admin)->putJson('/api/v1/admin/seo/checklist/search-console', ['done' => 'mungkin'])->assertStatus(422);
        $this->actingAs($this->staff('marketing'))->putJson('/api/v1/admin/seo/checklist/search-console', ['done' => true])->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'seo.checklist_updated')->count());
    }

    public function test_the_automatic_checklist_step_follows_product_descriptions(): void
    {
        $admin = $this->staff('super-admin');
        $state = fn () => collect($this->actingAs($admin)->getJson('/api/v1/admin/seo/overview')->json('data.checklist'))->firstWhere('key', 'product-descriptions');

        $this->assertFalse($state()['done'], 'tanpa produk tidak dianggap selesai');

        $this->product('Nastar Lengkap', 'NAS-OK-01', complete: true);
        $this->assertTrue($state()['done']);

        $this->product('Nastar Kosong', 'NAS-KOSONG-01');
        $this->assertFalse($state()['done']);
    }
}
