<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PromoContent;
use App\Models\User;
use App\Support\VideoLink;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Video "Di Balik Layar" (D-042, D-057): kelola oleh Marketing dan Super Admin, tampil di Galeri. */
class PromoVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tahap 2 (D-058): simpan video mencoba thumbnail otomatis; uji tidak boleh memanggil jaringan.
        Http::preventStrayRequests();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function video(array $overrides = []): PromoContent
    {
        $content = PromoContent::create([
            'type' => 'video', 'title' => 'Mengoles Nastar', 'video_url' => 'https://www.tiktok.com/@tuffero.patisserie/video/7300000000000000001',
            'provider' => 'tiktok', 'is_published' => true, ...$overrides,
        ]);
        $content->forceFill(['image_path' => 'promo/1/thumb.jpg'])->save();

        return $content;
    }

    /** @return array<string, array{string, string|null, string|null}> */
    public static function linkProvider(): array
    {
        return [
            'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            'youtube pendek' => ['https://youtu.be/dQw4w9WgXcQ?t=5', 'youtube', 'dQw4w9WgXcQ'],
            'youtube shorts' => ['https://m.youtube.com/shorts/abcDEF12345', 'youtube', 'abcDEF12345'],
            'tiktok video' => ['https://www.tiktok.com/@tuffero.patisserie/video/7300000000000000001', 'tiktok', '7300000000000000001'],
            'tiktok tautan pendek' => ['https://vm.tiktok.com/ZMabc123/', 'tiktok', null],
            'tiktok profil' => ['https://www.tiktok.com/@tuffero.patisserie', 'tiktok', null],
            'instagram reel' => ['https://www.instagram.com/reel/C1abcDEF_-/', 'instagram', 'C1abcDEF_-'],
            'instagram post' => ['https://www.instagram.com/p/C1abc/?igsh=x', 'instagram', 'C1abc'],
            'youtube tanpa id' => ['https://www.youtube.com/@tuffero', 'youtube', null],
        ];
    }

    #[DataProvider('linkProvider')]
    public function test_video_link_parser(string $url, ?string $provider, ?string $id): void
    {
        $this->assertSame(['provider' => $provider, 'external_id' => $id], VideoLink::parse($url));
    }

    public function test_create_and_update_derive_provider_and_id_server_side(): void
    {
        $this->actingAs($this->staff('marketing'));

        $id = $this->postJson('/api/v1/admin/contents', [
            'type' => 'video', 'title' => 'Video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'provider' => 'tiktok', 'external_id' => 'palsu',
        ])->assertCreated()->assertJsonPath('data.provider', 'youtube')->json('data.id');

        $this->assertSame('dQw4w9WgXcQ', PromoContent::find($id)->external_id);

        $this->patchJson("/api/v1/admin/contents/{$id}", ['title' => 'Video', 'video_url' => 'https://www.instagram.com/reel/C1abc/'])
            ->assertOk()->assertJsonPath('data.provider', 'instagram');
        $this->assertSame('C1abc', PromoContent::find($id)->external_id);
    }

    public function test_admin_list_can_be_scoped_to_video_or_promo(): void
    {
        $this->actingAs($this->staff('marketing'));
        $this->postJson('/api/v1/admin/contents', ['type' => 'video', 'title' => 'Video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $this->postJson('/api/v1/admin/contents', ['type' => 'text', 'title' => 'Promo', 'body' => 'Diskon'])->assertCreated();

        $this->getJson('/api/v1/admin/contents?scope=video')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.type', 'video');
        $this->getJson('/api/v1/admin/contents?scope=promo')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.type', 'text');
        $this->getJson('/api/v1/admin/contents')->assertOk()->assertJsonPath('data.meta.total', 2);
    }

    public function test_video_needs_thumbnail_before_publish(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff('marketing'));
        $id = $this->postJson('/api/v1/admin/contents', ['type' => 'video', 'title' => 'Video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ'])->json('data.id');
        $url = "/api/v1/admin/contents/{$id}";

        $this->patchJson($url, ['title' => 'Video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'is_published' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_published');

        $this->postJson("{$url}/image", ['image' => UploadedFile::fake()->image('thumb.jpg', 360, 640)])->assertOk();
        $this->patchJson($url, ['title' => 'Video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'is_published' => true])->assertOk()->assertJsonPath('data.is_published', true);
    }

    public function test_sort_order_is_validated_and_saved(): void
    {
        $this->actingAs($this->staff('marketing'));
        $base = ['type' => 'video', 'title' => 'Video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ'];

        $this->postJson('/api/v1/admin/contents', [...$base, 'sort_order' => -1])->assertUnprocessable()->assertJsonValidationErrors('sort_order');
        $this->postJson('/api/v1/admin/contents', [...$base, 'sort_order' => 10000])->assertUnprocessable()->assertJsonValidationErrors('sort_order');
        $this->postJson('/api/v1/admin/contents', [...$base, 'sort_order' => 3])->assertCreated()->assertJsonPath('data.sort_order', 3);
    }

    public function test_delete_is_soft_audited_and_limited_to_content_managers(): void
    {
        $content = $this->video();
        $url = "/api/v1/admin/contents/{$content->id}";

        foreach (['store-admin', 'owner', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->deleteJson($url)->assertForbidden();
        }
        $this->assertNotSoftDeleted($content);

        $marketing = $this->staff('marketing');
        $this->actingAs($marketing)->deleteJson($url)->assertOk();

        $this->assertSoftDeleted($content);
        $this->assertSame($marketing->id, AuditLog::where('action', 'content.deleted')->value('actor_user_id'));
        $this->getJson('/api/v1/admin/contents')->assertJsonCount(0, 'data.items');
        $this->actingAs($this->staff('super-admin'))->deleteJson("/api/v1/admin/contents/{$this->video()->id}")->assertOk();
    }

    public function test_delete_requires_login_and_mfa(): void
    {
        $content = $this->video();

        $this->deleteJson("/api/v1/admin/contents/{$content->id}")->assertUnauthorized();

        $noMfa = User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $noMfa->assignRole('marketing');
        $this->actingAs($noMfa)->deleteJson("/api/v1/admin/contents/{$content->id}")->assertForbidden();
    }

    public function test_gallery_shows_only_live_videos_with_thumbnail_in_order(): void
    {
        $this->video(['title' => 'Kedua', 'sort_order' => 2]);
        $this->video(['title' => 'Pertama', 'sort_order' => 1, 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'provider' => 'youtube']);
        $this->video(['title' => 'Draf', 'is_published' => false]);
        $this->video(['title' => 'Kedaluwarsa', 'ends_at' => now()->subDay()]);
        $this->video(['title' => 'Belum mulai', 'starts_at' => now()->addDay()]);
        $noThumb = PromoContent::create(['type' => 'video', 'title' => 'Tanpa thumbnail', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'provider' => 'youtube', 'is_published' => true]);
        $deleted = $this->video(['title' => 'Dihapus']);
        $deleted->delete();

        $html = $this->get('/galeri')->assertOk()->getContent();

        $this->assertStringContainsString('tf-gal-reels', $html);
        $this->assertLessThan(strpos($html, 'Kedua'), strpos($html, 'Pertama'));
        foreach (['Draf', 'Kedaluwarsa', 'Belum mulai', 'Tanpa thumbnail', 'Dihapus'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
        $this->assertStringContainsString('href="https://youtu.be/dQw4w9WgXcQ"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('Pertama, tonton di YouTube (membuka tab baru)', $html);
        $this->assertNotNull($noThumb->id);
    }

    public function test_gallery_escapes_titles_and_hides_section_without_live_videos(): void
    {
        $this->get('/galeri')->assertOk()->assertDontSee('tf-gal-reels')->assertDontSee('Di Balik Layar')->assertDontSee('/videos/');

        $this->video(['title' => '<script>alert(1)</script>']);
        $this->get('/galeri')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    /** @return array<string, array{string, string, string|null}> */
    public static function embedProvider(): array
    {
        return [
            'youtube' => ['youtube', 'dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&playsinline=1&rel=0'],
            'tiktok' => ['tiktok', '7300000000000000001', 'https://www.tiktok.com/player/v1/7300000000000000001?autoplay=1&rel=0&music_info=0&description=0'],
            'instagram' => ['instagram', 'C1abcDEF_-', 'https://www.instagram.com/p/C1abcDEF_-/embed/'],
            'youtube id salah' => ['youtube', 'abc', null],
            'youtube id berisi simbol' => ['youtube', 'dQw4w9"><script', null],
            'tiktok bukan angka' => ['tiktok', '../evil', null],
            'tiktok tautan pendek' => ['tiktok', '', null],
            'instagram simbol' => ['instagram', 'C1a/../x', null],
            'platform asing' => ['vimeo', 'dQw4w9WgXcQ', null],
        ];
    }

    #[DataProvider('embedProvider')]
    public function test_embed_url_is_built_only_from_validated_id(string $provider, string $id, ?string $expected): void
    {
        $this->assertSame($expected, (new PromoContent(['provider' => $provider, 'external_id' => $id]))->embedUrl());
    }

    public function test_gallery_cards_carry_embed_address_and_dialog_only_when_available(): void
    {
        $this->video(['title' => 'Putar YT', 'sort_order' => 1, 'provider' => 'youtube', 'external_id' => 'dQw4w9WgXcQ', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->video(['title' => 'Tautan pendek', 'sort_order' => 2, 'provider' => 'tiktok', 'external_id' => null, 'video_url' => 'https://vm.tiktok.com/ZMabc123/']);
        $this->video(['title' => 'Dia "bilang" <b>halo</b>', 'sort_order' => 3, 'provider' => 'instagram', 'external_id' => 'C1abcDEF', 'video_url' => 'https://www.instagram.com/reel/C1abcDEF/']);

        $html = $this->get('/galeri')->assertOk()->getContent();

        $this->assertStringContainsString('x-data="reels"', $html);
        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('data-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;playsinline=1&amp;rel=0"', $html);
        $this->assertStringContainsString('Putar YT, putar video', $html);
        $this->assertStringContainsString('data-embed=""', $html);
        $this->assertStringContainsString('Tautan pendek, tonton di TikTok (membuka tab baru)', $html);
        $this->assertStringContainsString('data-embed="https://www.instagram.com/p/C1abcDEF/embed/"', $html);
        $this->assertStringNotContainsString('<b>halo</b>', $html);
        $this->assertStringContainsString('data-title="Dia &quot;bilang&quot; &lt;b&gt;halo&lt;/b&gt;"', $html);
        $this->assertStringContainsString('Tutup video', $html);
    }
}
