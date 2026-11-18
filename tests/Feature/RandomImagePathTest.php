<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ImageService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** URL gambar publik tidak memuat ID database maupun nama berkas asli (audit paparan ID, 18 Nov 2026). */
class RandomImagePathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole('store-admin');

        return $user;
    }

    private function addImage(Product $product, UploadedFile $file)
    {
        return $this->post("/api/v1/admin/products/{$product->public_id}/images", ['image' => $file, 'alt' => 'Foto'], ['Accept' => 'application/json']);
    }

    public function test_product_upload_path_is_random_without_id_or_original_name(): void
    {
        $this->actingAs($this->admin());
        $product = Product::create(['name' => 'Dubai', 'slug' => 'dubai-'.uniqid()]);

        $this->addImage($product, UploadedFile::fake()->image('Dubai 1.jpg', 800, 800))->assertCreated();
        $this->addImage($product, UploadedFile::fake()->image('Dubai 1.jpg', 800, 800))->assertCreated();

        $paths = $product->images()->orderBy('sort_order')->pluck('path')->all();
        $this->assertCount(2, $paths);
        $this->assertNotSame($paths[0], $paths[1], 'Dua unggahan bernama sama tidak boleh bertabrakan.');
        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression(ImageService::RANDOM_PATH_PATTERN, $path);
            $this->assertStringStartsWith('products/', $path);
            $this->assertStringNotContainsString("/{$product->id}/", $path);
            $this->assertStringNotContainsStringIgnoringCase('dubai', $path);
            Storage::disk('public')->assertExists($path);
        }
        // Alt dan urutan tetap seperti sebelumnya.
        $this->assertSame(['Foto', 'Foto'], $product->images()->orderBy('sort_order')->pluck('alt')->all());
        $this->assertSame([1, 2], $product->images()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_removing_a_product_image_deletes_its_file(): void
    {
        $this->actingAs($this->admin());
        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar-'.uniqid()]);
        $this->addImage($product, UploadedFile::fake()->image('a.png', 400, 400))->assertCreated();
        $image = ProductImage::where('product_id', $product->id)->firstOrFail();

        $this->deleteJson("/api/v1/admin/images/{$image->public_id}")->assertOk();

        Storage::disk('public')->assertMissing($image->path);
    }

    public function test_replacing_a_campaign_banner_uses_a_new_random_path_and_deletes_the_old_file(): void
    {
        $this->actingAs($this->admin());
        $campaign = new Campaign(['title' => 'Promo Imlek', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'is_flash' => false, 'is_active' => true]);
        $campaign->forceFill(['slug' => 'promo-imlek'])->save();
        $url = "/api/v1/admin/campaigns/{$campaign->public_id}/image";

        $this->post($url, ['image' => UploadedFile::fake()->image('banner.jpg', 1200, 400)], ['Accept' => 'application/json'])->assertOk();
        $old = $campaign->fresh()->image_path;
        $this->post($url, ['image' => UploadedFile::fake()->image('banner.jpg', 1200, 400)], ['Accept' => 'application/json'])->assertOk();
        $new = $campaign->fresh()->image_path;

        $this->assertMatchesRegularExpression(ImageService::RANDOM_PATH_PATTERN, $new);
        $this->assertStringStartsWith('campaigns/', $new);
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_upload_validation_still_rejects_non_images(): void
    {
        $this->actingAs($this->admin());
        $product = Product::create(['name' => 'Pia', 'slug' => 'pia-'.uniqid()]);

        $this->addImage($product, UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'))->assertUnprocessable();
        $this->addImage($product, UploadedFile::fake()->create('fake.png', 10, 'image/png'))->assertUnprocessable();

        $this->assertSame(0, $product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_random_path_rejects_traversal_and_unknown_extensions(): void
    {
        foreach ([['../products', 'webp'], ['products/1', 'webp'], ['Products', 'webp'], ['products', 'php']] as [$area, $ext]) {
            try {
                ImageService::randomPath($area, $ext);
                $this->fail("Seharusnya ditolak: {$area}.{$ext}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
