<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Halaman toko Blade (D-053): dirender server, bahasa, SEO, escape, dan pembagian akses.
 */
class StorefrontPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function product(array $product = [], array $variant = []): Product
    {
        $model = Product::create(['name' => 'Nastar Uji', 'slug' => 'nastar-uji', 'is_published' => true, ...$product]);
        $model->variants()->create(['sku' => 'NAS-UJI-01', 'name' => 'Standar', 'price' => 123000, 'weight_gram' => 500, ...$variant]);

        return $model;
    }

    /** @return array<string, array{string, string}> */
    public static function publicPages(): array
    {
        return [
            'beranda' => ['/', 'Tuffero Bakery'],
            'tentang' => ['/tentang-kami', 'Tentang Kami | Tuffero Bakery'],
            'layanan' => ['/layanan', 'Layanan | Tuffero Bakery'],
            'katalog' => ['/produk', 'Produk | Tuffero Bakery'],
            'galeri' => ['/galeri', 'Galeri | Tuffero Bakery'],
            'kontak' => ['/kontak', 'Kontak | Tuffero Bakery'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_public_pages_serve_title_and_seo_tags_in_first_html(string $path, string $title): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee("<title>{$title}</title>", false)
            ->assertSee('name="description"', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('rel="canonical"', false)
            ->assertSee('hreflang="en"', false)
            ->assertDontSee('name="robots"', false);
    }

    /** @return array<string, array{string}> */
    public static function privatePages(): array
    {
        return [['/keranjang'], ['/checkout'], ['/cek-pesanan'], ['/masuk'], ['/daftar'], ['/pesanan/00000000-0000-4000-8000-000000000000']];
    }

    #[DataProvider('privatePages')]
    public function test_transaction_pages_are_not_indexed(string $path): void
    {
        $this->get($path)->assertOk()->assertSee('content="noindex, nofollow"', false)->assertDontSee('property="og:title"', false);
    }

    public function test_product_page_is_server_rendered_with_price_from_database_and_json_ld(): void
    {
        $this->product(['description' => 'Deskripsi uji', 'badges' => ['Terlaris']], ['max_order_quantity' => 12]);

        $this->get('/produk/nas-uji-01')
            ->assertOk()
            ->assertSee('<title>Nastar Uji | Tuffero Bakery</title>', false)
            ->assertSee('Deskripsi uji')
            ->assertSee('Rp123.000')
            ->assertSee('Terlaris')
            ->assertSee('Maksimal 12 per pesanan')
            ->assertSee('Stok sedang habis')
            ->assertSee('application/ld+json', false)
            ->assertSee('"price":123000', false);

        $this->get('/produk/NAS-UJI-01')->assertOk()->assertSee('Nastar Uji');
    }

    public function test_unpublished_or_unknown_product_answers_404_with_same_message(): void
    {
        $this->product(['is_published' => false]);

        $this->get('/produk/nas-uji-01')->assertNotFound()->assertSee('Produk tidak ditemukan');
        $this->get('/produk/tidak-ada')->assertNotFound()->assertSee('noindex', false);
    }

    public function test_catalog_lists_every_published_product_without_javascript(): void
    {
        $this->product();

        $this->get('/produk')->assertOk()->assertSee('/produk/nas-uji-01', false)->assertSee('Rp123.000');
    }

    public function test_user_content_is_escaped(): void
    {
        $this->product(['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(2)>']);

        $page = $this->get('/produk/nas-uji-01')->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false);
        $page->assertDontSee('<img src=x onerror=alert(2)>', false);

        $this->get('/produk?q='.urlencode('"><script>alert(3)</script>'))->assertOk()->assertDontSee('<script>alert(3)</script>', false);
        $this->get('/produk')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_language_comes_from_query_then_cookie_then_indonesian(): void
    {
        $this->get('/tentang-kami')->assertSee('<html lang="id">', false)->assertSee('Tentang Kami');
        $this->get('/tentang-kami?lang=en')->assertSee('<html lang="en">', false)->assertSee('About Us');
        $this->withUnencryptedCookie('tuffero_locale', 'en')->get('/tentang-kami')->assertSee('<html lang="en">', false);
        $this->withUnencryptedCookie('tuffero_locale', 'en')->get('/tentang-kami?lang=id')->assertSee('<html lang="id">', false);
    }

    public function test_invalid_language_falls_back_to_indonesian(): void
    {
        $this->get('/tentang-kami?lang=../../etc')->assertSee('<html lang="id">', false);
        $this->withUnencryptedCookie('tuffero_locale', '<script>')->get('/tentang-kami')->assertSee('<html lang="id">', false);
    }

    public function test_account_pages_redirect_guest_and_staff_but_open_for_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $customer = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole('marketing');

        foreach (['/akun', '/akun/pesanan', '/akun/alamat'] as $path) {
            $this->get($path)->assertRedirect('/masuk');
            $this->actingAs($staff)->get($path)->assertRedirect('/admin');
            $this->actingAs($customer)->get($path)->assertOk()->assertSee('noindex', false);
            auth()->logout();
        }
    }

    public function test_signed_in_user_skips_sign_in_pages(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('/masuk')->assertRedirect('/');
        $this->actingAs($customer)->get('/daftar')->assertRedirect('/');
        $this->actingAs($customer)->get('/masuk/verifikasi')->assertRedirect('/');
    }

    public function test_verification_page_without_state_returns_to_register(): void
    {
        $this->get('/masuk/verifikasi')->assertRedirect('/daftar');
    }

    public function test_inactive_account_is_signed_out_on_store_pages(): void
    {
        $customer = User::factory()->create(['is_active' => false]);

        $this->actingAs($customer)->get('/')->assertOk()->assertDontSee($customer->email);
        $this->assertGuest();
    }

    public function test_preview_otp_code_is_not_on_page_outside_local(): void
    {
        $this->get('/daftar')->assertOk()->assertDontSee('123456')->assertSee('"dev":false', false);
    }

    public function test_header_shows_signed_in_customer_without_exposing_to_guest(): void
    {
        $customer = User::factory()->create(['name' => 'Uji <Pelanggan>']);

        $this->get('/')->assertDontSee('tf-account-menu', false);
        $this->actingAs($customer)->get('/')->assertSee('tf-account-menu', false)->assertSee('Uji &lt;Pelanggan&gt;', false);
    }

    public function test_unknown_path_is_404_and_unknown_api_stays_json(): void
    {
        $this->get('/halaman-tak-ada')->assertNotFound()->assertSee('noindex', false);
        $this->getJson('/api/v1/tidak-ada')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_sitemap_lists_public_pages_and_published_products_only(): void
    {
        $this->product();
        Product::create(['name' => 'Draf', 'slug' => 'draf', 'is_published' => false])->variants()->create(['sku' => 'DRAF-01', 'name' => 'Standar', 'price' => 1, 'weight_gram' => 1]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('/produk/nas-uji-01', false)
            ->assertSee('/tentang-kami', false)
            ->assertDontSee('/produk/draf-01', false)
            ->assertDontSee('/keranjang', false);
    }

    public function test_admin_pages_stay_on_vue_shell(): void
    {
        foreach (['/login', '/admin', '/admin/produk'] as $path) {
            $this->get($path)->assertOk()->assertViewIs('admin')->assertDontSee('tf-page-loader', false);
        }
    }

    public function test_store_pages_do_not_load_vue_bundle(): void
    {
        $this->withVite();
        $this->get('/tentang-kami')->assertOk()->assertDontSee('resources/js/app.js', false)->assertSee('resources/js/store.js', false);
    }
}
