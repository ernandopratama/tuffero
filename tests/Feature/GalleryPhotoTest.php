<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GalleryPhoto;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Foto Galeri toko dikelola dari dashboard (D-083): Marketing dan Super Admin; Pemilik hanya lihat; Admin Toko tidak punya akses. */
class GalleryPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return ['title' => 'Nastar Baru', 'title_en' => 'New Nastar', 'category' => 'nastar', 'sort_order' => 5, 'image' => UploadedFile::fake()->image('foto.jpg', 2400, 1200), ...$overrides];
    }

    public function test_migration_seeds_the_existing_gallery_photos(): void
    {
        $this->assertSame(28, GalleryPhoto::count());
        $first = GalleryPhoto::liveList()->first();
        $this->assertSame('/images/Produk/gallery/Nastar Sultan 3.webp', $first->imageUrl());
        $this->assertSame('/images/Produk/Nastar Sultan 3_result.webp', $first->fullUrl());
        $this->assertTrue($first->is_featured);
    }

    public function test_storefront_gallery_renders_photos_from_the_database_in_order_and_hides_unpublished(): void
    {
        GalleryPhoto::query()->delete();
        GalleryPhoto::forceCreate(['title' => 'Foto Kedua', 'title_en' => 'Second Photo', 'category' => 'pia', 'sort_order' => 20, 'image_path' => 'gallery/b.webp']);
        GalleryPhoto::forceCreate(['title' => 'Foto Pertama <b>', 'category' => 'nastar', 'sort_order' => 10, 'image_path' => 'gallery/a.webp']);
        GalleryPhoto::forceCreate(['title' => 'Tersembunyi', 'category' => 'snack', 'is_published' => false, 'image_path' => 'gallery/h.webp']);

        $html = $this->get('/galeri')->assertOk()->getContent();

        $this->assertStringContainsString('/storage/gallery/a.webp', $html);
        $this->assertStringContainsString('Foto Pertama &lt;b&gt;', $html);
        $this->assertStringNotContainsString('Tersembunyi', $html);
        $this->assertLessThan(strpos($html, 'Foto Kedua'), strpos($html, 'Foto Pertama'));
    }

    public function test_marketing_can_create_update_replace_image_and_delete(): void
    {
        $this->actingAs($this->staff('marketing'));

        $id = $this->post('/api/v1/admin/gallery-photos', $this->payload(), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.title', 'Nastar Baru')->json('data.id');

        $photo = GalleryPhoto::find($id);
        $this->assertStringStartsWith('gallery/', $photo->image_path);
        $this->assertStringEndsWith('.webp', $photo->image_path);
        Storage::disk('public')->assertExists($photo->image_path);
        $this->assertTrue($photo->is_published);

        $this->patchJson("/api/v1/admin/gallery-photos/{$id}", ['title' => 'Nastar Ubah', 'category' => 'macaron', 'is_published' => false, 'is_featured' => true])
            ->assertOk()->assertJsonPath('data.category', 'macaron')->assertJsonPath('data.is_published', false);

        $old = $photo->image_path;
        $this->post("/api/v1/admin/gallery-photos/{$id}/image", ['image' => UploadedFile::fake()->image('baru.png', 800, 800)], ['Accept' => 'application/json'])->assertOk();
        $photo->refresh();
        $this->assertNotSame($old, $photo->image_path);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($photo->image_path);

        $this->deleteJson("/api/v1/admin/gallery-photos/{$id}")->assertOk();
        $this->assertSoftDeleted('gallery_photos', ['id' => $id]);

        foreach (['gallery.created', 'gallery.updated', 'gallery.image_changed', 'gallery.deleted'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists(), $action);
        }
    }

    public function test_replacing_image_never_deletes_the_bundled_static_file(): void
    {
        $this->actingAs($this->staff('super-admin'));
        $seeded = GalleryPhoto::first();

        $this->post("/api/v1/admin/gallery-photos/{$seeded->id}/image", ['image' => UploadedFile::fake()->image('x.jpg', 600, 600)], ['Accept' => 'application/json'])->assertOk();

        $this->assertFileExists(public_path('images/Produk/gallery/Nastar Sultan 3.webp'));
        $this->assertNull($seeded->refresh()->full_path);
    }

    public function test_uploaded_image_is_reencoded_and_capped(): void
    {
        $this->actingAs($this->staff('marketing'));

        $id = $this->post('/api/v1/admin/gallery-photos', $this->payload(['image' => UploadedFile::fake()->image('besar.jpg', 4000, 2000)]), ['Accept' => 'application/json'])->json('data.id');

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get(GalleryPhoto::find($id)->image_path));
        $this->assertSame(1600, $w);
        $this->assertSame(800, $h);
    }

    public function test_validation(): void
    {
        $this->actingAs($this->staff('marketing'));
        $headers = ['Accept' => 'application/json'];

        $this->post('/api/v1/admin/gallery-photos', $this->payload(['category' => 'roti']), $headers)->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->post('/api/v1/admin/gallery-photos', $this->payload(['title' => '']), $headers)->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->post('/api/v1/admin/gallery-photos', $this->payload(['sort_order' => 10000]), $headers)->assertUnprocessable()->assertJsonValidationErrors('sort_order');
        $this->post('/api/v1/admin/gallery-photos', $this->payload(['image' => null]), $headers)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post('/api/v1/admin/gallery-photos', $this->payload(['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')]), $headers)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post('/api/v1/admin/gallery-photos', $this->payload(['image' => UploadedFile::fake()->create('x.jpg', 6000, 'image/jpeg')]), $headers)->assertUnprocessable()->assertJsonValidationErrors('image');
        // Berkas rusak yang berekstensi gambar ditolak.
        $this->post('/api/v1/admin/gallery-photos', $this->payload(['image' => UploadedFile::fake()->createWithContent('x.jpg', 'bukan gambar')]), $headers)->assertUnprocessable();

        $id = GalleryPhoto::first()->id;
        $this->patchJson("/api/v1/admin/gallery-photos/{$id}", ['title' => 'x', 'category' => 'nastar', 'image' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame(28, GalleryPhoto::count());
    }

    public function test_admin_list_filters(): void
    {
        $this->actingAs($this->staff('marketing'));

        $this->getJson('/api/v1/admin/gallery-photos?category=pia')->assertOk()->assertJsonPath('data.meta.total', 3);
        $this->getJson('/api/v1/admin/gallery-photos?search=Dubai')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/admin/gallery-photos?is_published=0')->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function accessProvider(): array
    {
        return [
            'marketing lihat' => ['marketing', 'getJson', '/api/v1/admin/gallery-photos', 200],
            'super admin lihat' => ['super-admin', 'getJson', '/api/v1/admin/gallery-photos', 200],
            'pemilik lihat' => ['owner', 'getJson', '/api/v1/admin/gallery-photos', 200],
            'pemilik tidak ubah' => ['owner', 'patchJson', '/api/v1/admin/gallery-photos/1', 403],
            'pemilik tidak hapus' => ['owner', 'deleteJson', '/api/v1/admin/gallery-photos/1', 403],
            'admin toko tidak lihat' => ['store-admin', 'getJson', '/api/v1/admin/gallery-photos', 403],
            'admin toko tidak hapus' => ['store-admin', 'deleteJson', '/api/v1/admin/gallery-photos/1', 403],
            'cs tidak lihat' => ['customer-service', 'getJson', '/api/v1/admin/gallery-photos', 403],
        ];
    }

    #[DataProvider('accessProvider')]
    public function test_access_by_role(string $role, string $method, string $url, int $status): void
    {
        $this->actingAs($this->staff($role))->{$method}($url)->assertStatus($status);
    }

    public function test_guest_cannot_manage(): void
    {
        $this->getJson('/api/v1/admin/gallery-photos')->assertUnauthorized();
        $this->deleteJson('/api/v1/admin/gallery-photos/1')->assertUnauthorized();
    }

    public function test_english_page_uses_english_title_with_indonesian_fallback(): void
    {
        GalleryPhoto::query()->delete();
        foreach ([['Judul ID', 'English Title'], ['Hanya ID', null]] as $i => [$id, $en]) {
            GalleryPhoto::forceCreate(['title' => $id, 'title_en' => $en, 'category' => 'nastar', 'sort_order' => $i, 'image_path' => "gallery/{$i}.webp"]);
        }

        $html = $this->withUnencryptedCookie('tuffero_locale', 'en')->get('/galeri')->assertOk()->getContent();

        $this->assertStringContainsString('English Title', $html);
        $this->assertStringContainsString('Hanya ID', $html);
        $this->assertStringNotContainsString('Judul ID', $html);
    }
}
