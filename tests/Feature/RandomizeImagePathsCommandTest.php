<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\PromoContent;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Perintah images:randomize-paths: dry-run, apply, idempoten, cleanup, rollback. */
class RandomizeImagePathsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function legacyImage(): ProductImage
    {
        $product = Product::create(['name' => 'Dubai', 'slug' => 'dubai-'.uniqid()]);
        $path = "products/{$product->id}/Dubai 1.webp";
        Storage::disk('public')->put($path, 'isi-gambar');

        return $product->images()->create(['path' => $path, 'alt' => 'Dubai', 'sort_order' => 1]);
    }

    private function manifest(): string
    {
        return Storage::disk('local')->files('image-path-migrations')[0];
    }

    public function test_dry_run_changes_nothing(): void
    {
        $image = $this->legacyImage();

        $this->artisan('images:randomize-paths')->expectsOutputToContain('1 berkas akan dipindah')->assertSuccessful();

        $this->assertSame($image->path, $image->fresh()->path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_apply_copies_updates_db_keeps_old_file_and_is_idempotent(): void
    {
        $image = $this->legacyImage();
        $thumb = PromoContent::forceCreate(['type' => 'video', 'title' => 'Video', 'image_path' => 'promo/7/auto-abc.jpg']);
        Storage::disk('public')->put('promo/7/auto-abc.jpg', 'jpg');

        $this->artisan('images:randomize-paths', ['--apply' => true])->assertSuccessful();

        $new = $image->fresh()->path;
        $this->assertMatchesRegularExpression(ImageService::RANDOM_PATH_PATTERN, $new);
        $this->assertSame('isi-gambar', Storage::disk('public')->get($new));
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame('Dubai', $image->fresh()->alt);
        $this->assertStringContainsString('/auto-', $thumb->fresh()->image_path);
        $this->assertStringEndsWith('.jpg', $thumb->fresh()->image_path);

        $this->artisan('images:randomize-paths', ['--apply' => true])->expectsOutputToContain('0 berkas akan dipindah')->assertSuccessful();
        $this->assertSame($new, $image->fresh()->path);
    }

    public function test_cleanup_deletes_only_verified_old_files(): void
    {
        $image = $this->legacyImage();
        $this->artisan('images:randomize-paths', ['--apply' => true])->assertSuccessful();

        $this->artisan('images:randomize-paths', ['--cleanup' => $this->manifest()])->assertSuccessful();

        Storage::disk('public')->assertMissing($image->path);
        Storage::disk('public')->assertExists($image->fresh()->path);
    }

    public function test_rollback_restores_old_path_and_removes_copy(): void
    {
        $image = $this->legacyImage();
        $this->artisan('images:randomize-paths', ['--apply' => true])->assertSuccessful();
        $new = $image->fresh()->path;

        $this->artisan('images:randomize-paths', ['--rollback' => $this->manifest()])->assertSuccessful();

        $this->assertSame($image->path, $image->fresh()->path);
        Storage::disk('public')->assertMissing($new);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_static_paths_and_bad_manifests_are_rejected(): void
    {
        Product::create(['name' => 'X', 'slug' => 'x'])->images()->create(['path' => 'images/Produk/x.webp', 'alt' => 'x', 'sort_order' => 1]);

        $this->artisan('images:randomize-paths')->expectsOutputToContain('0 berkas akan dipindah')->assertSuccessful();
        $this->artisan('images:randomize-paths', ['--rollback' => '../../.env'])->assertFailed();
    }
}
