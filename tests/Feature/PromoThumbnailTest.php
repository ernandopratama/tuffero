<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PromoContent;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Thumbnail otomatis video (D-058): hanya alamat tetap, gambar divalidasi dan digambar ulang. */
class PromoThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private const YT = 'https://youtu.be/dQw4w9WgXcQ';

    private const TT = 'https://www.tiktok.com/@tuffero.patisserie/video/7300000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs($this->staff('marketing'));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function jpeg(int $width = 720, int $height = 1280): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function image(string $body, int $status = 200): PromiseInterface
    {
        return Http::response($body, $status, ['Content-Type' => 'image/jpeg']);
    }

    private function create(string $url, string $title = 'Video'): TestResponse
    {
        return $this->postJson('/api/v1/admin/contents', ['type' => 'video', 'title' => $title, 'video_url' => $url]);
    }

    public function test_youtube_thumbnail_is_fetched_reencoded_stored_and_audited(): void
    {
        Http::fake(['img.youtube.com/vi/dQw4w9WgXcQ/maxresdefault.jpg' => $this->image($this->jpeg(1280, 720))]);

        $response = $this->create(self::YT)->assertCreated()->assertJsonPath('data.thumbnail_status', 'fetched')->assertJsonPath('data.can_auto_thumbnail', true);

        $content = PromoContent::findOrFail($response->json('data.id'));
        $this->assertStringContainsString("promo/{$content->id}/auto-", $content->image_path);
        Storage::disk('public')->assertExists($content->image_path);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('public')->get($content->image_path))['mime']);
        $this->assertSame(1, AuditLog::where('action', 'content.thumbnail_fetched')->count());
    }

    public function test_youtube_falls_back_to_smaller_thumbnail(): void
    {
        Http::fake([
            'img.youtube.com/vi/dQw4w9WgXcQ/maxresdefault.jpg' => Http::response('', 404),
            'img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg' => $this->image($this->jpeg(480, 360)),
        ]);

        $this->create(self::YT)->assertCreated()->assertJsonPath('data.thumbnail_status', 'fetched');
    }

    public function test_failure_does_not_block_saving(): void
    {
        Http::fake(['img.youtube.com/*' => Http::response('', 404)]);

        $this->create(self::YT)->assertCreated()->assertJsonPath('data.thumbnail_status', 'failed')->assertJsonPath('data.image_url', null);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['img.youtube.com/*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        $this->create(self::YT)->assertCreated()->assertJsonPath('data.thumbnail_status', 'failed');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254'));
    }

    public function test_non_images_and_bad_sizes_are_refused(): void
    {
        $bodies = [
            'html' => '<html><script>alert(1)</script></html>',
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'kecil' => $this->jpeg(50, 50),
            'terlalu besar' => str_repeat('A', 2 * 1024 * 1024 + 10),
        ];

        foreach ($bodies as $body) {
            Http::fake(['img.youtube.com/*' => $this->image($body)]);

            $this->create(self::YT)->assertCreated()->assertJsonPath('data.thumbnail_status', 'failed');
        }
    }

    public function test_hidden_payload_in_image_is_dropped_by_reencoding(): void
    {
        Http::fake(['img.youtube.com/*' => $this->image($this->jpeg().'<?php echo "x"; ?>')]);

        $id = $this->create(self::YT)->assertJsonPath('data.thumbnail_status', 'fetched')->json('data.id');

        $this->assertStringNotContainsString('<?php', Storage::disk('public')->get(PromoContent::findOrFail($id)->image_path));
    }

    public function test_tiktok_and_instagram_links_make_no_request(): void
    {
        $this->create(self::TT)->assertCreated()->assertJsonPath('data.thumbnail_status', 'unsupported')->assertJsonPath('data.can_auto_thumbnail', false);
        $this->create('https://www.instagram.com/reel/C1abc/')->assertCreated()->assertJsonPath('data.thumbnail_status', 'unsupported')->assertJsonPath('data.can_auto_thumbnail', false);
        $this->create('https://vm.tiktok.com/ZMabc123/')->assertCreated()->assertJsonPath('data.can_auto_thumbnail', false);

        Http::assertNothingSent();
    }

    public function test_url_change_replaces_auto_thumbnail_but_keeps_manual_one(): void
    {
        Http::fake(['img.youtube.com/*' => fn () => $this->image($this->jpeg())]);
        $id = $this->create(self::YT)->json('data.id');
        $first = PromoContent::findOrFail($id)->image_path;

        $this->patchJson("/api/v1/admin/contents/{$id}", ['title' => 'Video', 'video_url' => 'https://youtu.be/abcDEF12345'])->assertOk()->assertJsonPath('data.thumbnail_status', 'fetched');
        $second = PromoContent::findOrFail($id)->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->postJson("/api/v1/admin/contents/{$id}/image", ['image' => UploadedFile::fake()->image('mine.jpg', 360, 640)])->assertOk();
        $manual = PromoContent::findOrFail($id)->image_path;
        $this->assertStringNotContainsString('/auto-', $manual);

        $this->patchJson("/api/v1/admin/contents/{$id}", ['title' => 'Video', 'video_url' => 'https://youtu.be/zzzDEF12345'])->assertOk()->assertJsonPath('data.thumbnail_status', null);
        $this->assertSame($manual, PromoContent::findOrFail($id)->image_path);
    }

    public function test_refresh_endpoint_overwrites_and_respects_permissions(): void
    {
        $online = false;
        Http::fake(['img.youtube.com/*' => function () use (&$online) {
            return $online ? $this->image($this->jpeg()) : Http::response('', 404);
        }]);
        $id = $this->create(self::YT)->json('data.id');
        $this->postJson("/api/v1/admin/contents/{$id}/image", ['image' => UploadedFile::fake()->image('mine.jpg', 360, 640)])->assertOk();
        $url = "/api/v1/admin/contents/{$id}/thumbnail";

        $this->postJson($url)->assertUnprocessable();

        $online = true;
        $this->postJson($url)->assertOk()->assertJsonPath('data.thumbnail_status', 'fetched');
        $this->assertStringContainsString('/auto-', PromoContent::findOrFail($id)->image_path);

        foreach (['store-admin', 'owner', 'customer-service'] as $role) {
            $this->actingAs($this->staff($role))->postJson($url)->assertForbidden();
        }

        $insta = PromoContent::create(['type' => 'video', 'title' => 'IG', 'video_url' => 'https://www.instagram.com/reel/C1abc/', 'provider' => 'instagram', 'external_id' => 'C1abc']);
        $this->actingAs($this->staff('super-admin'))->postJson("/api/v1/admin/contents/{$insta->id}/thumbnail")->assertUnprocessable()->assertJsonValidationErrors('video_url');
    }
}
