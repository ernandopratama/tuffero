<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Halaman galat toko bermaskot: 404 di dalam layout toko, kode lain mandiri; /api/* tetap JSON. */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_store_url_shows_the_mascot_404_in_indonesian(): void
    {
        $this->get('/tidak-ada')
            ->assertNotFound()
            ->assertSee('Halaman tidak ditemukan')
            ->assertSee('err-mascot', false)
            ->assertSee('noindex', false);
    }

    public function test_404_follows_the_locale_cookie(): void
    {
        $this->withUnencryptedCookie('tuffero_locale', 'en')
            ->get('/tidak-ada')
            ->assertNotFound()
            ->assertSee('Page not found');
    }

    public function test_unknown_api_url_stays_json(): void
    {
        $this->getJson('/api/v1/tidak-ada')->assertNotFound()->assertJsonMissing(['err-mascot']);
    }

    /** @return array<string, array{int, string}> */
    public static function standaloneCodes(): array
    {
        return [
            '403' => [403, 'Akses ditolak'],
            '419' => [419, 'Sesi sudah berakhir'],
            '429' => [429, 'Terlalu banyak permintaan'],
            '500' => [500, 'Ada yang tidak beres'],
            '503' => [503, 'Sedang dipercantik'],
        ];
    }

    #[DataProvider('standaloneCodes')]
    public function test_standalone_error_views_render_without_the_store_layout(int $code, string $title): void
    {
        $html = view("errors.$code")->render();

        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString('err-mascot', $html);
        $this->assertStringNotContainsString('tf-store-data', $html);
    }

    public function test_429_shows_the_retry_after_seconds(): void
    {
        $this->app['router']->get('/__limit', fn () => abort(429, '', ['Retry-After' => 42]));

        $this->get('/__limit')->assertStatus(429)->assertSee('42 detik');
    }
}
