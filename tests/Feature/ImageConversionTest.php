<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\User;
use App\Services\ImageService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Semua unggahan gambar admin (D-086): hanya JPG, JPEG, PNG, dan semuanya disimpan sebagai WebP. */
class ImageConversionTest extends TestCase
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

    private function assertWebp(string $path): void
    {
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(Storage::disk('public')->get($path))[2]);
    }

    public function test_product_photo_is_stored_as_webp_and_rejects_webp_and_gif(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar']);
        $url = "/api/v1/admin/products/{$product->id}/images";

        $this->postJson($url, ['image' => UploadedFile::fake()->image('a.jpg', 3000, 2000), 'alt' => 'Foto'])->assertCreated();
        $this->postJson($url, ['image' => UploadedFile::fake()->image('b.png', 800, 800), 'alt' => 'Foto'])->assertCreated();

        foreach ($product->images as $image) {
            $this->assertWebp($image->path);
        }
        $this->assertLessThanOrEqual(1600, max(...array_slice(getimagesizefromstring(Storage::disk('public')->get($product->images->first()->path)), 0, 2)), 'sisi terpanjang dibatasi 1600');

        $this->postJson($url, ['image' => UploadedFile::fake()->image('c.webp', 400, 400), 'alt' => 'Foto'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson($url, ['image' => UploadedFile::fake()->image('d.gif', 400, 400), 'alt' => 'Foto'])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_campaign_banner_is_stored_as_webp(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $campaign = new Campaign(['title' => 'Promo', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'is_active' => true]);
        $campaign->forceFill(['slug' => 'promo'])->save();

        $this->post("/api/v1/admin/campaigns/{$campaign->id}/image", ['image' => UploadedFile::fake()->image('banner.png', 1200, 400)], ['Accept' => 'application/json'])->assertOk();

        $this->assertWebp($campaign->fresh()->image_path);
        $this->post("/api/v1/admin/campaigns/{$campaign->id}/image", ['image' => UploadedFile::fake()->image('x.webp', 600, 200)], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_gallery_photo_is_stored_as_webp(): void
    {
        $this->actingAs($this->staff('marketing'));

        $id = $this->post('/api/v1/admin/gallery-photos', ['title' => 'Foto', 'category' => 'nastar', 'image' => UploadedFile::fake()->image('foto.jpeg', 900, 900)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->assertWebp(\App\Models\GalleryPhoto::findOrFail($id)->image_path);
        $this->post('/api/v1/admin/gallery-photos', ['title' => 'Foto', 'category' => 'nastar', 'image' => UploadedFile::fake()->image('foto.webp', 900, 900)], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_png_transparency_is_preserved(): void
    {
        $png = imagecreatetruecolor(40, 40);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 255, 0, 0, 127));
        $file = tempnam(sys_get_temp_dir(), 'png');
        imagepng($png, $file);

        $webp = app(ImageService::class)->toWebp(new UploadedFile($file, 'logo.png', 'image/png', null, true));
        $decoded = imagecreatefromstring($webp);

        $this->assertGreaterThan(100, (imagecolorat($decoded, 5, 5) >> 24) & 0x7F, 'piksel transparan tetap transparan');
        unlink($file);
    }

    public function test_exif_rotation_is_applied_before_exif_is_dropped(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('Ekstensi exif tidak ada.');
        }

        // JPEG 60x20 dengan EXIF Orientation=6 (putar 90 derajat searah jarum jam): hasil harus 20x60.
        $jpeg = imagecreatetruecolor(60, 20);
        $tmp = tempnam(sys_get_temp_dir(), 'jpg');
        imagejpeg($jpeg, $tmp);
        $bytes = file_get_contents($tmp);
        $exif = "Exif\x00\x00".'MM'."\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";
        file_put_contents($tmp, substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));

        $webp = app(ImageService::class)->toWebp(new UploadedFile($tmp, 'foto.jpg', 'image/jpeg', null, true));
        [$w, $h] = getimagesizefromstring($webp);

        $this->assertSame([20, 60], [$w, $h]);
        unlink($tmp);
    }

    public function test_non_image_content_is_rejected_even_with_image_extension(): void
    {
        $file = UploadedFile::fake()->createWithContent('a.jpg', '<?php echo 1;');

        $this->expectException(ValidationException::class);
        app(ImageService::class)->toWebp($file);
    }
}
