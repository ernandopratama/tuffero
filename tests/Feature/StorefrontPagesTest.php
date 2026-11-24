<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\StoreSite;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
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

    /** Produk uji di kategori `$slug` (dibuat bila belum ada). SKU, slug, dan nama harus berbeda per produk. */
    private function categorized(string $slug, array $product = [], array $variant = []): Product
    {
        $category = ProductCategory::firstOrCreate(['slug' => $slug], ['name_id' => ucfirst($slug), 'name_en' => ucfirst($slug), 'is_active' => true]);

        return $this->product(['product_category_id' => $category->id, ...$product], $variant);
    }

    /** @return array<string, array{string, string}> */
    public static function publicPages(): array
    {
        return [
            'beranda' => ['/', 'Tuffero Bakery Malang – Nastar, Macaron &amp; Kue Kering Premium'],
            'tentang' => ['/tentang-kami', 'Tentang Tuffero Bakery | Kue Kering dan Pastry dari Malang'],
            'layanan' => ['/layanan', 'Layanan Pesan Online, Hampers &amp; Oleh-oleh | Tuffero Bakery'],
            'katalog' => ['/produk', 'Katalog Nastar, Macaron, Pia &amp; Kue Kering | Tuffero Bakery'],
            'galeri' => ['/galeri', 'Galeri Foto Produk dan Dapur | Tuffero Bakery'],
            'kontak' => ['/kontak', 'Kontak dan Alamat Tuffero Bakery di Malang'],
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
            ->assertSee('href="/kontak#syarat"', false)
            ->assertSee('href="/kontak#privasi"', false)
            ->assertDontSee('name="robots"', false);
    }

    /** @return array<string, array{string}> */
    public static function privatePages(): array
    {
        return [['/keranjang'], ['/checkout'], ['/cek-pesanan'], ['/ajukan-refund'], ['/masuk'], ['/daftar'], ['/pesanan/00000000-0000-4000-8000-000000000000']];
    }

    #[DataProvider('privatePages')]
    public function test_transaction_pages_are_not_indexed(string $path): void
    {
        $this->get($path)->assertOk()->assertSee('content="noindex, nofollow"', false)->assertDontSee('property="og:title"', false);
    }

    public function test_footer_track_link_opens_the_order_lookup_page(): void
    {
        // Dulu `href="#"`; tautan Lacak Pesanan di footer harus ke /cek-pesanan seperti di header dan navigasi bawah.
        $html = $this->get('/tentang-kami')->assertOk()->getContent();
        $footer = substr($html, (int) strpos($html, '<footer id="contact"'));

        $this->assertStringContainsString('href="/cek-pesanan">'.trans('store.landing.top.track', [], 'id').'</a>', $footer);
        $this->assertStringNotContainsString('href="#"', $footer);
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
            ->assertSee('"price":123000', false)
            ->assertSee('"availability":"https://schema.org/OutOfStock"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('"hasMerchantReturnPolicy":{"@type":"MerchantReturnPolicy"', false)
            ->assertSee('"merchantReturnDays":3', false);

        // Aturan retur di JSON-LD juga harus terbaca pelanggan di S&K (/kontak#syarat).
        $this->get('/kontak')->assertSee('Retur dan klaim: batas waktu')->assertSee('3 hari kalender');
        $this->get('/kontak?lang=en')->assertSee('within 3 calendar days');

        // R-07: satu alamat per produk.
        $this->get('/produk/NAS-UJI-01')->assertStatus(301)->assertRedirect('/produk/nas-uji-01');
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

    public function test_bottom_nav_links_account_by_role_and_stays_off_checkout_and_sign_in(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $customer = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole('marketing');

        $this->get('/produk')->assertOk()->assertSee('tf-bnav', false)
            ->assertSee('<a href="/produk" class="tf-bnav-tab" aria-current="page"', false)
            ->assertSee('<a href="/masuk" class="tf-bnav-tab"', false)
            ->assertDontSee('tf-bnav-out', false);
        $this->actingAs($customer)->get('/')->assertSee('<a href="/akun" class="tf-bnav-tab"', false)->assertSee('tf-bnav-out', false);
        auth()->logout();
        $this->actingAs($staff)->get('/')->assertSee('<a href="/admin" class="tf-bnav-tab"', false);
        auth()->logout();

        $this->get('/checkout')->assertOk()->assertDontSee('tf-bnav', false);
        $this->get('/masuk')->assertOk()->assertDontSee('tf-bnav', false);
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

        (new Campaign)->forceFill(['title' => 'Promo Lebaran', 'slug' => 'promo-lebaran', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true])->save();
        (new Campaign)->forceFill(['title' => 'Promo Lama', 'slug' => 'promo-lama', 'starts_at' => now()->subWeek(), 'ends_at' => now()->subDay(), 'is_active' => true])->save();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('/produk/nas-uji-01', false)
            ->assertSee('/promo/promo-lebaran', false)
            ->assertDontSee('/promo/promo-lama', false)
            // R-12: daftar promo dan voucher tipis, tidak masuk sitemap (halaman tiap promo tetap).
            ->assertDontSee('/voucher</loc>', false)
            ->assertDontSee('/promo</loc>', false)
            ->assertSee('<lastmod>', false)
            ->assertSee('/tentang-kami', false)
            ->assertDontSee('/produk/draf-01', false)
            ->assertDontSee('/keranjang', false);
    }

    public function test_uppercase_product_address_redirects_permanently_and_keeps_the_query(): void
    {
        $this->product();

        $this->get('/produk/NAS-UJI-01?lang=en&utm_source=x')
            ->assertStatus(301)
            ->assertRedirect('/produk/nas-uji-01?lang=en&utm_source=x');
        $this->get('/produk/nas-uji-01')->assertOk();
        $this->get('/produk/TIDAK-ADA')->assertStatus(301)->assertRedirect('/produk/tidak-ada');
        $this->get('/produk/tidak-ada')->assertNotFound();
    }

    public function test_index_php_address_redirects_permanently_to_the_clean_address(): void
    {
        $base = url('/');

        $this->get('/index.php')->assertStatus(301)->assertHeader('Location', $base.'/');
        $this->get('/index.php?lang=en')->assertStatus(301)->assertHeader('Location', $base.'/?lang=en');
        $this->get('/index.php/produk?lang=en')->assertStatus(301)->assertHeader('Location', $base.'/produk?lang=en');
        // Alamat sekali ini bukan index.php: tidak ada pengalihan.
        $this->get('/')->assertOk();
    }

    public function test_promo_and_voucher_lists_are_noindex(): void
    {
        $this->get('/promo')->assertOk()->assertSee('content="noindex, nofollow"', false)->assertDontSee('rel="canonical"', false);
        $this->get('/voucher')->assertOk()->assertSee('content="noindex, nofollow"', false);
    }

    public function test_static_page_titles_stay_within_search_result_length(): void
    {
        foreach (['id', 'en'] as $locale) {
            foreach (['about', 'services', 'gallery', 'contact', 'catalog', 'promo', 'vouchers'] as $page) {
                $title = trans("store.pages.{$page}.meta", [], $locale);
                $this->assertLessThanOrEqual(60, mb_strlen($title), "{$locale} {$page}: {$title}");
            }
        }

        $this->get('/produk?lang=en')->assertSee('<title>Nastar, Macaron, Pia &amp; Cookies Catalog | Tuffero Bakery</title>', false);
    }

    public function test_home_json_ld_links_the_website_to_the_bakery_with_a_logo(): void
    {
        $this->product();

        $base = url('/');

        $this->get('/')
            ->assertOk()
            ->assertSee('"@id":"'.$base.'/#bakery"', false)
            ->assertSee('"logo":"'.$base.'/images/Logo/Logo%20Tuffero%20Bakery-Htm.webp"', false)
            ->assertSee('"@type":"WebSite"', false)
            ->assertSee('"publisher":{"@id":"'.$base.'/#bakery"}', false);
        $this->assertFileExists(public_path('images/Logo/Logo Tuffero Bakery-Htm.webp'));
    }

    public function test_catalog_lists_every_product_in_item_list_json_ld_with_a_breadcrumb(): void
    {
        $this->product();

        $this->get('/produk')
            ->assertOk()
            ->assertSee('"@type":"CollectionPage"', false)
            ->assertSee('"@type":"ItemList"', false)
            ->assertSee('"numberOfItems":1', false)
            ->assertSee('"url":"'.url('/produk/nas-uji-01').'"', false)
            ->assertSee('"@type":"BreadcrumbList"', false);
    }

    public function test_category_page_lists_its_products_with_a_facts_only_intro_and_seo_tags(): void
    {
        $this->categorized('nastar', [], ['shelf_life_days' => 21]);
        $this->categorized('macaron', ['name' => 'Macaron Uji', 'slug' => 'macaron-uji'], ['sku' => 'MAC-UJI-01']);

        $this->get('/kategori/nastar')
            ->assertOk()
            ->assertSee('<title>Nastar dari Malang | Tuffero Bakery</title>', false)
            ->assertSee('<h1', false)
            ->assertSee('Tuffero menyediakan 1 pilihan Nastar: Nastar Uji.')
            ->assertSee('Berat kemasan 500 gram.')
            ->assertSee('Masa simpan 21 hari.')
            ->assertSee('/produk/nas-uji-01', false)
            ->assertDontSee('/produk/mac-uji-01', false)
            ->assertSee('rel="canonical" href="'.url('/kategori/nastar').'"', false)
            ->assertSee('hreflang="en"', false)
            ->assertSee('"@type":"ItemList"', false)
            ->assertSee('"numberOfItems":1', false)
            ->assertDontSee('content="noindex', false);

        $this->get('/kategori/nastar?lang=en')
            ->assertOk()
            ->assertSee('<title>Nastar from Malang | Tuffero Bakery</title>', false)
            ->assertSee('In Nastar, Tuffero offers 1 options: ');
    }

    public function test_only_listed_categories_that_have_products_get_a_page(): void
    {
        $this->categorized('pia', ['name' => 'Pia Uji', 'slug' => 'pia-uji'], ['sku' => 'PIA-UJI-01']);

        $this->get('/kategori/pia')->assertNotFound();          // ada produk, tetapi tidak ada di daftar halaman kategori
        $this->get('/kategori/hampers')->assertNotFound();      // tidak ada produk
        $this->get('/kategori/nastar')->assertNotFound();       // di daftar, tetapi belum ada produk tayang
        $this->get('/kategori/tidak-ada')->assertNotFound();
    }

    public function test_uppercase_category_address_redirects_permanently_and_keeps_the_query(): void
    {
        $this->categorized('nastar');

        $this->get('/kategori/NASTAR?lang=en')->assertStatus(301)->assertRedirect('/kategori/nastar?lang=en');
    }

    public function test_category_pages_are_in_the_sitemap_and_linked_from_catalog_and_product(): void
    {
        $this->categorized('nastar');
        $this->categorized('pia', ['name' => 'Pia Uji', 'slug' => 'pia-uji'], ['sku' => 'PIA-UJI-01']);

        $this->get('/sitemap.xml')
            ->assertSee('/kategori/nastar</loc>', false)
            ->assertDontSee('/kategori/pia</loc>', false);

        $this->get('/produk')->assertSee('href="/kategori/nastar"', false)->assertDontSee('href="/kategori/pia"', false);

        // Breadcrumb produk (tampilan dan JSON-LD) memuat kategori hanya bila halamannya ada.
        $this->get('/produk/nas-uji-01')
            ->assertSee('href="/kategori/nastar"', false)
            ->assertSee('"item":"'.url('/kategori/nastar').'"', false);
        $this->get('/produk/pia-uji-01')->assertOk()->assertDontSee('/kategori/pia', false);
    }

    public function test_category_titles_stay_within_search_result_length(): void
    {
        foreach (['id' => 'Brownies & Cake', 'en' => 'Brownies & Cakes'] as $locale => $longestName) {
            $title = str_replace('{name}', $longestName, trans('store.pages.category.title', [], $locale));
            $this->assertLessThanOrEqual(60, mb_strlen($title), $title);
        }
    }

    public function test_home_has_local_business_json_ld(): void
    {
        // Kode pos dan kota di businessJsonLd() ditulis terpisah dari teks alamat footer; uji ini gagal bila salah satunya diubah sendirian.
        $footer = trans('store.landing.footer.address', [], 'id');
        $this->assertStringContainsString('65151', $footer);
        $this->assertStringContainsString('Malang', $footer);
        $this->assertStringContainsString('Jawa Timur', $footer);

        $this->product();
        $this->get('/')
            ->assertOk()
            ->assertSee('"@type":"Bakery"', false)
            ->assertSee('"postalCode":"65151"', false)
            ->assertSee('"dayOfWeek":"Saturday"', false)
            ->assertSee('"servesCuisine":["Kue Kering","Pastry","Macaron"]', false)
            ->assertDontSee('"dayOfWeek":"Sunday"', false)
            ->assertDontSee('aggregateRating', false)
            // Satu produk: harga termurah = termahal, jadi priceRange satu angka.
            ->assertSee('"priceRange":"Rp123.000"', false);
    }

    public function test_home_images_carry_intrinsic_size(): void
    {
        $this->get('/')
            ->assertSee('src="/images/Tuffero Bakery Treats Showcase.webp" width="1671" height="941"', false)
            // Foto kartu "Cara pesan": tangkapan layar halaman Produk dan Checkout harus berkas yang ada (sebelumnya step-*.jpg 404).
            ->assertSee('src="/images/landing/cara-katalog.webp" width="960" height="675"', false)
            ->assertSee('src="/images/landing/cara-checkout.webp" width="960" height="675"', false)
            ->assertSee('src="/images/landing/cara-terima.webp" width="1000" height="746"', false)
            ->assertDontSee('step-pick.jpg', false);
        $this->assertSame('', (string) app(StoreSite::class)->imageSize('/images/tidak-ada.webp'));
    }

    public function test_admin_pages_stay_on_vue_shell(): void
    {
        foreach (['/login', '/lupa-kata-sandi', '/admin', '/admin/produk'] as $path) {
            $this->get($path)->assertOk()->assertViewIs('admin')->assertDontSee('tf-page-loader', false);
        }
    }

    public function test_store_pages_do_not_load_vue_bundle(): void
    {
        // File hot sendiri: tanpa ini hasil bergantung pada ada tidaknya public/hot (dev server) dan public/build (hash nama berkas).
        $hot = storage_path('framework/testing/vite-hot');
        file_put_contents($hot, 'http://localhost:5173');
        // withVite() mengembalikan instance Vite asli, jadi file hot harus dipasang sesudahnya.
        $this->withVite();
        Vite::useHotFile($hot);

        try {
            $this->get('/tentang-kami')->assertOk()->assertDontSee('resources/js/app.js', false)->assertSee('http://localhost:5173/resources/js/store.js', false);
        } finally {
            @unlink($hot);
        }
    }
}
