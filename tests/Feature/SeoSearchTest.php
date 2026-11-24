<?php

namespace Tests\Feature;

use App\Jobs\SyncSeoData;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Seo\GoogleAccessToken;
use App\Services\SeoSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Dashboard SEO Fase 2 (D-152): sinkron Search Console dan PageSpeed, laporan, dan tombol Perbarui sekarang.
 * Semua panggilan Google dipalsukan, termasuk token; tidak ada kredensial atau jaringan sungguhan.
 */
class SeoSearchTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'kunci-rahasia-uji';

    private const PATH = 'C:/rahasia/kunci-service-account.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['app.url' => 'https://tuffero.test']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** Kunci Google "terisi": properti dan token palsu; kunci PageSpeed bila diminta. */
    private function configure(bool $searchConsole = true, bool $pageSpeed = true): void
    {
        config(['services.google_seo' => [
            'property' => $searchConsole ? 'sc-domain:tuffero.test' : null,
            'credentials_path' => $searchConsole ? self::PATH : null,
            'pagespeed_key' => $pageSpeed ? self::KEY : null,
        ]]);
        $this->app->instance(GoogleAccessToken::class, new class extends GoogleAccessToken
        {
            public function configured(): bool
            {
                return filled(config('services.google_seo.property'));
            }

            public function token(): string
            {
                return 'token-palsu';
            }
        });
    }

    private function product(): void
    {
        $category = ProductCategory::firstOrCreate(['slug' => 'nastar'], ['name_id' => 'Nastar', 'name_en' => 'Nastar', 'is_active' => true]);
        $product = Product::create(['name' => 'Nastar Uji', 'slug' => 'nastar-uji', 'is_published' => true, 'product_category_id' => $category->id]);
        $product->variants()->create(['sku' => 'NAS-OK-01', 'name' => 'Standar', 'price' => 100000, 'weight_gram' => 500]);
    }

    /** @return array<string, mixed> */
    private function psi(int $score = 87, bool $field = true): array
    {
        return [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => $score / 100]],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => 3120.4],
                    'cumulative-layout-shift' => ['numericValue' => 0.0431],
                    'total-blocking-time' => ['numericValue' => 260.7],
                ],
            ],
            'loadingExperience' => $field ? [
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2800, 'category' => 'AVERAGE'],
                    'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 190],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 5],
                ],
                'overall_category' => 'AVERAGE',
                'origin_fallback' => true,
            ] : [],
        ];
    }

    /**
     * Palsukan Google. `$clicks` dipakai untuk halaman pertama supaya penarikan kedua bisa dibedakan.
     *
     * @param  array<string, mixed>  $overrides  'search' => status/array respons Search Analytics, 'speed' => callable(url)
     */
    private function fakeGoogle(int $clicks = 61, array $overrides = []): void
    {
        $pages = [];
        foreach (range(1, 60) as $i) {
            $pages[] = ['keys' => ['2026-11-10', "https://tuffero.test/p-$i"], 'clicks' => $i === 1 ? $clicks : 61 - $i, 'impressions' => 100 + $i, 'ctr' => 0.1, 'position' => 4.0];
        }
        $pages[] = ['keys' => ['2026-11-11', 'https://tuffero.test/produk'], 'clicks' => 7, 'impressions' => 70, 'ctr' => 0.1, 'position' => 6.0];
        $rows = [
            'date' => [
                ['keys' => ['2026-11-10'], 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 5.0],
                ['keys' => ['2026-11-11'], 'clicks' => 20, 'impressions' => 300, 'ctr' => 0.0667, 'position' => 8.0],
            ],
            'date,page' => $pages,
            'date,query' => [
                ['keys' => ['2026-11-10', 'nastar malang'], 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 3.0],
                ['keys' => ['2026-11-10', 'macaron malang'], 'clicks' => 9, 'impressions' => 90, 'ctr' => 0.1, 'position' => 2.0],
            ],
        ];

        // Stub pertama menang bila ada dua fake; mulai dari pabrik bersih agar pemanggilan kedua berlaku.
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($rows, $overrides) {
            $url = $request->url();
            if (str_contains($url, 'searchAnalytics/query')) {
                return $overrides['search'] ?? Http::response(['rows' => $rows[implode(',', $request['dimensions'])]]);
            }
            if (str_ends_with($url, '/sitemaps')) {
                return Http::response(['sitemap' => [['path' => 'https://tuffero.test/sitemap.xml']]]);
            }
            if (str_contains($url, 'runPagespeed')) {
                return isset($overrides['speed']) ? $overrides['speed']($url) : Http::response($this->psi());
            }

            return Http::response('tidak diharapkan', 500);
        });
    }

    public function test_sync_stores_daily_totals_and_the_top_50_pages_and_queries_per_day(): void
    {
        $this->configure(pageSpeed: false);
        $this->fakeGoogle();

        $status = app(SeoSyncService::class)->sync();

        $this->assertSame('ok', $status['state'], $status['message']);
        $this->assertTrue($status['property_ok']);
        $this->assertTrue($status['sitemap_found']);
        $this->assertSame(2, DB::table('seo_search_daily')->count());
        $this->assertSame(20, DB::table('seo_search_daily')->where('date', '2026-11-11')->value('clicks'));
        // 60 halaman pada 10 Nov: hanya 50 teratas (klik terbanyak) yang disimpan; ditambah 1 baris pada 11 Nov.
        $this->assertSame(51, DB::table('seo_search_pages')->count());
        $this->assertSame(0, DB::table('seo_search_pages')->where('page', 'https://tuffero.test/p-60')->count());
        $this->assertSame(1, DB::table('seo_search_pages')->where('page', 'https://tuffero.test/p-50')->count());
        $this->assertSame(2, DB::table('seo_search_queries')->count());

        // Permintaan: baca-saja ke properti domain (di-URL-encode), hanya data final, dengan token.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sites/sc-domain%3Atuffero.test/searchAnalytics/query')
            && $request['dataState'] === 'final' && $request['type'] === 'web' && $request->hasHeader('Authorization', 'Bearer token-palsu'));
        // Total harian diambil terpisah, hanya dengan dimensi tanggal.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'searchAnalytics/query') && $request['dimensions'] === ['date']);
    }

    public function test_pulling_again_updates_rows_instead_of_duplicating_them(): void
    {
        $this->configure(pageSpeed: false);
        $service = app(SeoSyncService::class);

        $this->fakeGoogle(clicks: 61);
        $service->sync();
        $this->fakeGoogle(clicks: 99);
        $service->sync();

        $this->assertSame(2, DB::table('seo_search_daily')->count());
        $this->assertSame(51, DB::table('seo_search_pages')->count());
        $this->assertSame(99, (int) DB::table('seo_search_pages')->where('page', 'https://tuffero.test/p-1')->value('clicks'));
    }

    public function test_pagespeed_runs_store_lab_and_field_data_for_the_main_pages(): void
    {
        $this->configure(searchConsole: false);
        $this->product();
        $this->fakeGoogle();

        $status = app(SeoSyncService::class)->sync();

        $this->assertSame('ok', $status['state'], $status['message']);
        $this->assertSame(['https://tuffero.test/', 'https://tuffero.test/produk', 'https://tuffero.test/kategori/nastar', 'https://tuffero.test/produk/nas-ok-01'], DB::table('seo_pagespeed_runs')->orderBy('id')->pluck('url')->all());
        $run = DB::table('seo_pagespeed_runs')->first();
        $this->assertSame([87, 3120, 261, 'mobile'], [(int) $run->score, (int) $run->lcp_ms, (int) $run->tbt_ms, $run->strategy]);
        $this->assertEquals(0.043, (float) $run->cls);
        // Data pengguna nyata: persentil CLS berskala 100 (5 = 0,05); hasil tingkat situs bila halaman tidak punya cukup data.
        $this->assertSame([2800, 190, 'AVERAGE', 'origin'], [(int) $run->field_lcp_ms, (int) $run->field_inp_ms, $run->field_category, $run->field_scope]);
        $this->assertEquals(0.05, (float) $run->field_cls);
        // Tanpa kunci Search Console tidak ada panggilan ke Search Console.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'webmasters'));
        Http::assertSent(fn (Request $request) => $request['strategy'] === 'MOBILE' && $request['key'] === self::KEY);
    }

    public function test_pagespeed_without_field_data_leaves_those_columns_empty(): void
    {
        $this->configure(searchConsole: false);
        $this->fakeGoogle(overrides: ['speed' => fn () => Http::response($this->psi(field: false))]);

        app(SeoSyncService::class)->sync();

        $run = DB::table('seo_pagespeed_runs')->first();
        $this->assertSame(87, (int) $run->score);
        $this->assertNull($run->field_lcp_ms);
        $this->assertNull($run->field_scope);
    }

    public function test_a_google_error_is_reported_without_secrets_and_other_parts_still_run(): void
    {
        $this->configure();
        $this->fakeGoogle(overrides: [
            'search' => Http::response(['error' => ['message' => 'User does not have sufficient permission for site']], 403),
            'speed' => fn (string $url) => str_ends_with($url, '/produk') || str_contains($url, 'url=https%3A%2F%2Ftuffero.test%2Fproduk')
                ? Http::response('galat', 500)
                : Http::response($this->psi()),
        ]);

        $status = app(SeoSyncService::class)->sync();

        $this->assertSame('error', $status['state']);
        $this->assertFalse($status['property_ok']);
        $this->assertStringContainsString('Google menjawab 403', $status['message']);
        $this->assertStringContainsString('sufficient permission', $status['message']);
        $this->assertStringContainsString('PageSpeed /produk', $status['message']);
        foreach ([self::KEY, self::PATH, 'token-palsu'] as $secret) {
            $this->assertStringNotContainsString($secret, $status['message']);
        }
        $this->assertSame(0, DB::table('seo_search_daily')->count());
        // PageSpeed beranda tetap tersimpan walau halaman lain dan Search Console gagal.
        $this->assertGreaterThanOrEqual(1, DB::table('seo_pagespeed_runs')->count());
    }

    public function test_nothing_is_called_without_keys(): void
    {
        $this->configure(searchConsole: false, pageSpeed: false);
        Http::fake();

        $status = app(SeoSyncService::class)->sync();

        $this->assertSame('not_configured', $status['state']);
        Http::assertNothingSent();
    }

    public function test_rows_older_than_sixteen_months_are_pruned(): void
    {
        $this->configure(pageSpeed: false);
        $old = now()->subMonths(17)->toDateString();
        DB::table('seo_search_daily')->insert([['date' => $old, 'clicks' => 1], ['date' => now()->subDays(5)->toDateString(), 'clicks' => 2]]);
        DB::table('seo_search_pages')->insert(['date' => $old, 'page' => 'https://tuffero.test/lama']);
        DB::table('seo_pagespeed_runs')->insert([['url' => 'https://tuffero.test/', 'ran_at' => now()->subMonths(17)], ['url' => 'https://tuffero.test/', 'ran_at' => now()]]);

        app(SeoSyncService::class)->prune();

        $this->assertSame([2], DB::table('seo_search_daily')->pluck('clicks')->map(fn ($c) => (int) $c)->all());
        $this->assertSame(0, DB::table('seo_search_pages')->count());
        $this->assertSame(1, DB::table('seo_pagespeed_runs')->count());
    }

    public function test_the_report_compares_with_the_previous_period_and_ranks_by_clicks(): void
    {
        $admin = $this->staff('super-admin');
        $day = fn (int $ago) => now()->subDays($ago)->toDateString();
        // Periode 7 hari berakhir di data terakhir (hari ke-3); periode sebelumnya sama panjang.
        DB::table('seo_search_daily')->insert([
            ['date' => $day(3), 'clicks' => 30, 'impressions' => 300, 'position' => 4],
            ['date' => $day(4), 'clicks' => 10, 'impressions' => 100, 'position' => 8],
            ['date' => $day(12), 'clicks' => 5, 'impressions' => 100, 'position' => 10],
        ]);
        DB::table('seo_search_queries')->insert([
            ['date' => $day(3), 'query' => 'nastar malang', 'clicks' => 20, 'impressions' => 200, 'position' => 3],
            ['date' => $day(4), 'query' => 'nastar malang', 'clicks' => 10, 'impressions' => 100, 'position' => 6],
            ['date' => $day(3), 'query' => 'macaron', 'clicks' => 4, 'impressions' => 90, 'position' => 9],
        ]);
        DB::table('seo_search_pages')->insert(['date' => $day(3), 'page' => 'https://tuffero.test/produk', 'clicks' => 12, 'impressions' => 150, 'position' => 5]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/seo/search?days=7')->assertOk();

        $response->assertJsonPath('data.report.days', 7)->assertJsonPath('data.report.has_data', true)
            ->assertJsonPath('data.report.totals.clicks', 40)->assertJsonPath('data.report.totals.impressions', 400)
            ->assertJsonPath('data.report.totals.ctr', 0.1)
            // Posisi rata-rata berbobot tayangan: (4x300 + 8x100) / 400 = 5.
            ->assertJsonPath('data.report.totals.position', 5)
            ->assertJsonPath('data.report.previous.clicks', 5)
            ->assertJsonPath('data.report.queries.0.key', 'nastar malang')
            ->assertJsonPath('data.report.queries.0.clicks', 30)
            ->assertJsonPath('data.report.queries.0.position', 4)   // (3x200 + 6x100) / 300
            ->assertJsonPath('data.report.queries.1.key', 'macaron')
            ->assertJsonPath('data.report.pages.0.key', 'https://tuffero.test/produk')
            ->assertJsonCount(2, 'data.report.daily')
            ->assertJsonPath('data.configured.search_console', false);

        // Periode di luar 7, 28, 90 dikembalikan ke 28.
        $this->actingAs($admin)->getJson('/api/v1/admin/seo/search?days=5')->assertOk()->assertJsonPath('data.report.days', 28);
    }

    public function test_the_report_is_empty_but_valid_without_data(): void
    {
        $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/seo/search')->assertOk()
            ->assertJsonPath('data.report.has_data', false)->assertJsonPath('data.report.totals', null)
            ->assertJsonPath('data.sync.state', 'never')->assertJsonPath('data.report.speed', []);
    }

    public function test_only_owner_and_super_admin_read_search_data_and_start_a_sync(): void
    {
        Queue::fake();
        $this->configure();

        foreach (['marketing', 'store-admin', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/seo/search')->assertForbidden();
            $this->actingAs($this->staff($role))->postJson('/api/v1/admin/seo/search/sync')->assertForbidden();
        }
        Queue::assertNothingPushed();
    }

    public function test_the_sync_button_queues_one_job_and_refuses_a_second_while_running(): void
    {
        Queue::fake();
        $admin = $this->staff('super-admin');

        // Kunci belum diisi: ditolak dan tidak ada antrean.
        $this->configure(searchConsole: false, pageSpeed: false);
        $this->actingAs($admin)->postJson('/api/v1/admin/seo/search/sync')->assertStatus(422);
        Queue::assertNothingPushed();

        $this->configure();
        $this->actingAs($admin)->postJson('/api/v1/admin/seo/search/sync')->assertStatus(202)->assertJsonPath('data.state', 'running');
        Queue::assertPushed(SyncSeoData::class, 1);

        $this->actingAs($admin)->postJson('/api/v1/admin/seo/search/sync')->assertStatus(409);
        Queue::assertPushed(SyncSeoData::class, 1);
    }

    public function test_overview_shows_google_totals_and_confirms_checklist_steps_from_the_sync(): void
    {
        $this->configure();
        $this->fakeGoogle();
        $admin = $this->staff('super-admin');
        $step = fn (string $key) => collect($this->actingAs($admin)->getJson('/api/v1/admin/seo/overview')->json('data.checklist'))->firstWhere('key', $key);

        $this->assertFalse($step('search-console')['done']);

        app(SeoSyncService::class)->sync();

        foreach (['search-console', 'sitemap-submitted', 'pagespeed'] as $key) {
            $this->assertTrue($step($key)['done'], $key);
            $this->assertTrue($step($key)['confirmed'], $key);
        }
        $this->assertFalse($step('brand-name')['confirmed']);
        $this->actingAs($admin)->getJson('/api/v1/admin/seo/overview')->assertOk()
            ->assertJsonPath('data.google.connected', true)->assertJsonPath('data.google.sync.state', 'ok')
            ->assertJsonPath('data.google.totals.clicks', 30);
    }

    public function test_the_sync_job_runs_the_service_and_is_scheduled_daily(): void
    {
        $this->configure(pageSpeed: false);
        $this->fakeGoogle();

        (new SyncSeoData)->handle(app(SeoSyncService::class));
        $this->assertSame('ok', app(SeoSyncService::class)->status()['state']);

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'seo:sync');
        $this->assertNotNull($event);
        $this->assertSame('Asia/Jakarta', $event->timezone);
        $this->assertSame('0 3 * * *', $event->expression);
    }
}
