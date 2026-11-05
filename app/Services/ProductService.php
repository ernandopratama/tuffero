<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    /** @param array<string, mixed> $data */
    public function createProduct(array $data, ?User $actor): Product
    {
        return DB::transaction(function () use ($data, $actor) {
            $product = Product::create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'slug' => $this->uniqueSlug($data['name'])])->refresh();

            AuditLog::record('product.created', $product, ['name' => $product->name], $actor?->id);

            return $product;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(Product $product, array $data, User $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $actor) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (($data['is_published'] ?? false) && ! $locked->variants()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['is_published' => 'Produk butuh minimal satu varian aktif sebelum dipublikasikan.']);
            }

            AuditLog::saveWithAudit($locked, $data, 'product.updated', $actor);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function createVariant(Product $product, array $data, ?User $actor): ProductVariant
    {
        return DB::transaction(function () use ($product, $data, $actor) {
            $variant = $product->variants()->create($data);

            AuditLog::record('variant.created', $variant, ['sku' => $variant->sku, 'price' => $variant->price], $actor?->id);

            return $variant;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateVariant(ProductVariant $variant, array $data, User $actor): ProductVariant
    {
        return DB::transaction(function () use ($variant, $data, $actor) {
            $product = Product::whereKey($variant->product_id)->lockForUpdate()->firstOrFail();

            $isLastActive = $variant->is_active
                && ! $product->variants()->where('is_active', true)->whereKeyNot($variant->id)->exists();

            if ($product->is_published && $isLastActive && ($data['is_active'] ?? true) === false) {
                throw ValidationException::withMessages(['is_active' => 'Ini varian aktif terakhir. Tarik publikasi produk dulu.']);
            }

            AuditLog::saveWithAudit($variant, $data, 'variant.updated', $actor);

            return $variant;
        });
    }

    public function addImage(Product $product, UploadedFile $file, string $alt, User $actor): ProductImage
    {
        $path = $file->store("products/{$product->id}", 'public');

        return DB::transaction(function () use ($product, $path, $alt, $actor) {
            $image = $product->images()->create([
                'path' => $path,
                'alt' => $alt,
                'sort_order' => (int) $product->images()->max('sort_order') + 1,
            ]);

            AuditLog::record('product.image_added', $product, ['image_id' => $image->id], $actor->id);

            return $image;
        });
    }

    public function removeImage(ProductImage $image, User $actor): void
    {
        DB::transaction(function () use ($image, $actor) {
            $image->delete();

            AuditLog::record('product.image_removed', $image->product, ['image_id' => $image->id], $actor->id);
        });

        Storage::disk('public')->delete($image->path);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'produk';
        $slug = $base;

        for ($i = 2; Product::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
