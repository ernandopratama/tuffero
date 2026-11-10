<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NewsItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Berita popup beranda (D-085): semua role admin melihat, Marketing dan Super Admin mengelola. */
class NewsItemAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return ['kind' => 'news', 'title' => 'Buka lebih awal', 'body' => 'Mulai hari ini toko buka pukul 08.00.', 'is_published' => true, ...$overrides];
    }

    private function make(array $overrides = []): NewsItem
    {
        return NewsItem::create($this->payload($overrides));
    }

    /** @return array<string, array{string, int, int}> peran, status lihat, status kelola */
    public static function roles(): array
    {
        return [
            'super admin' => ['super-admin', 200, 201],
            'marketing' => ['marketing', 200, 201],
            'pemilik' => ['owner', 200, 403],
            'admin toko' => ['store-admin', 200, 403],
            'cs' => ['customer-service', 200, 403],
            'gudang' => ['warehouse', 200, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_view_but_only_marketing_and_super_admin_can_manage(string $role, int $view, int $manage): void
    {
        $this->actingAs($this->staff($role));

        $this->getJson('/api/v1/admin/news')->assertStatus($view);
        $this->postJson('/api/v1/admin/news', $this->payload())->assertStatus($manage);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/news')->assertUnauthorized();
    }

    public function test_writes_require_enrolled_mfa(): void
    {
        $this->actingAs($this->staff('marketing', ['mfa_totp_confirmed_at' => null]))
            ->postJson('/api/v1/admin/news', $this->payload())
            ->assertForbidden();
    }

    public function test_marketing_creates_updates_and_deletes_with_audit(): void
    {
        $this->actingAs($this->staff('marketing'));

        $id = $this->postJson('/api/v1/admin/news', $this->payload(['kind' => 'promo', 'title_en' => 'Opening earlier', 'cta_label' => 'Lihat', 'cta_url' => '/promo/flash']))
            ->assertCreated()
            ->assertJsonPath('data.kind', 'promo')
            ->assertJsonPath('data.status', 'live')
            ->json('data.id');

        $this->patchJson("/api/v1/admin/news/{$id}", $this->payload(['title' => 'Judul baru', 'is_published' => false]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Judul baru')
            ->assertJsonPath('data.status', 'draft');

        $this->deleteJson("/api/v1/admin/news/{$id}")->assertOk();
        $this->assertSoftDeleted('news_items', ['id' => $id]);

        foreach (['news.created', 'news.updated', 'news.deleted'] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->count(), $action);
        }
    }

    public function test_index_reports_popup_slots(): void
    {
        $this->actingAs($this->staff('owner'));
        $this->make(['title' => 'A']);
        $this->make(['title' => 'B', 'is_published' => false]);

        $this->getJson('/api/v1/admin/news')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.popup.limit', 5)
            ->assertJsonPath('data.popup.used', 1);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'judul kosong' => [['title' => ''], 'title'],
            'teks kosong' => [['body' => ''], 'body'],
            'teks terlalu panjang' => [['body' => str_repeat('a', 301)], 'body'],
            'jenis tidak dikenal' => [['kind' => 'iklan'], 'kind'],
            'tautan javascript' => [['cta_label' => 'Klik', 'cta_url' => 'javascript:alert(1)'], 'cta_url'],
            'tautan data' => [['cta_label' => 'Klik', 'cta_url' => 'data:text/html,<b>x</b>'], 'cta_url'],
            'tautan http biasa' => [['cta_label' => 'Klik', 'cta_url' => 'http://situs.test'], 'cta_url'],
            'tautan tanpa skema ke situs lain' => [['cta_label' => 'Klik', 'cta_url' => '//evil.test/x'], 'cta_url'],
            'tautan dengan backslash' => [['cta_label' => 'Klik', 'cta_url' => '/\\evil.test'], 'cta_url'],
            'tautan dengan spasi' => [['cta_label' => 'Klik', 'cta_url' => '/promo/a b'], 'cta_url'],
            'tautan tanpa teks tombol' => [['cta_url' => '/promo'], 'cta_label'],
            'teks tombol tanpa tautan' => [['cta_label' => 'Klik'], 'cta_url'],
            'selesai sebelum mulai' => [['starts_at' => '2026-12-02T10:00:00Z', 'ends_at' => '2026-12-01T10:00:00Z'], 'ends_at'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalid')]
    public function test_validation(array $overrides, string $field): void
    {
        $this->actingAs($this->staff('marketing'));

        $this->postJson('/api/v1/admin/news', $this->payload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_safe_links_are_accepted(): void
    {
        $this->actingAs($this->staff('marketing'));

        $this->postJson('/api/v1/admin/news', $this->payload(['cta_label' => 'Lihat', 'cta_url' => '/promo/flash-sale?x=1']))->assertCreated();
        $this->postJson('/api/v1/admin/news', $this->payload(['cta_label' => 'Buka', 'cta_url' => 'https://www.tiktok.com/@tuffero']))->assertCreated();
    }

    public function test_image_is_converted_to_webp_and_old_file_is_removed(): void
    {
        $this->actingAs($this->staff('marketing'));
        $item = $this->make();

        $first = $this->post("/api/v1/admin/news/{$item->id}/image", ['image' => UploadedFile::fake()->image('a.jpg', 2400, 1200)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.image_url');

        $this->assertStringEndsWith('.webp', $first);
        $stored = $item->fresh()->image_path;
        Storage::disk('public')->assertExists($stored);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($stored));
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(Storage::disk('public')->get($stored))[2]);
        $this->assertLessThanOrEqual(1280, max($w, $h), 'sisi terpanjang dibatasi');

        $this->post("/api/v1/admin/news/{$item->id}/image", ['image' => UploadedFile::fake()->image('b.png', 800, 400)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($stored);

        $this->deleteJson("/api/v1/admin/news/{$item->id}/image")->assertOk()->assertJsonPath('data.image_url', null);
        $this->assertSame(0, count(Storage::disk('public')->allFiles('news')));
    }

    public function test_only_jpg_jpeg_and_png_are_accepted(): void
    {
        $this->actingAs($this->staff('marketing'));
        $item = $this->make();
        $url = "/api/v1/admin/news/{$item->id}/image";
        $json = ['Accept' => 'application/json'];

        $this->post($url, ['image' => UploadedFile::fake()->image('x.webp', 400, 400)], $json)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post($url, ['image' => UploadedFile::fake()->image('x.gif', 400, 400)], $json)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post($url, ['image' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')], $json)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post($url, ['image' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')], $json)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post($url, ['image' => UploadedFile::fake()->image('big.jpg', 600, 600)->size(4000)], $json)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post($url, ['image' => UploadedFile::fake()->image('ok.jpeg', 600, 600)], $json)->assertOk();
    }

    public function test_view_only_roles_cannot_upload_or_delete(): void
    {
        $item = $this->make();
        $this->actingAs($this->staff('store-admin'));

        $this->post("/api/v1/admin/news/{$item->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->patchJson("/api/v1/admin/news/{$item->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/admin/news/{$item->id}")->assertForbidden();
        $this->assertNotSoftDeleted('news_items', ['id' => $item->id]);
    }
}
