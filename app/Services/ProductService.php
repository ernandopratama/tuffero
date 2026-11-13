<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductType;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantDiscount;
use App\Services\Translation\ProductTranslationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(private ProductTranslationService $translations, private ImageService $images) {}

    /** @param array<string, mixed> $data */
    public function createProduct(array $data, ?User $actor): Product
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertTypeFits($data['product_category_id'] ?? null, $data['product_type_id'] ?? null);

            $product = Product::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'name_en' => $data['name_en'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'translation_mode' => $data['translation_mode'] ?? 'ai',
                'slug' => $this->uniqueSlug($data['name']),
                'product_category_id' => $data['product_category_id'] ?? null,
                'product_type_id' => $data['product_type_id'] ?? null,
            ])->refresh();

            AuditLog::record('product.created', $product, ['name' => $product->name], $actor?->id);
            $this->translations->queue($product);

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

            // Ganti kategori tanpa menyebut jenis: jenis lama dilepas karena milik kategori lain.
            if (array_key_exists('product_category_id', $data) && ! array_key_exists('product_type_id', $data) && $data['product_category_id'] !== $locked->product_category_id) {
                $data['product_type_id'] = null;
            }
            $categoryId = array_key_exists('product_category_id', $data) ? $data['product_category_id'] : $locked->product_category_id;
            $typeId = array_key_exists('product_type_id', $data) ? $data['product_type_id'] : $locked->product_type_id;
            $this->assertTypeFits($categoryId, $typeId);

            $clearing = array_key_exists('product_category_id', $data) && $categoryId === null && $locked->is_published && ($data['is_published'] ?? true);
            if ((($data['is_published'] ?? false) || $clearing) && ! $categoryId) {
                throw ValidationException::withMessages(['product_category_id' => 'Pilih kategori sebelum produk dipublikasikan.']);
            }

            // Admin mengetik terjemahan: produk jadi manual supaya AI tidak menimpanya. Kembali ke AI memicu terjemahan ulang.
            $edited = false;
            $variantNames = $data['variant_names_en'] ?? [];
            unset($data['variant_names_en']);
            foreach ($locked->variants as $variant) {
                if (array_key_exists($variant->id, $variantNames)) {
                    $name = filled($variantNames[$variant->id]) ? trim((string) $variantNames[$variant->id]) : null;
                    if ($name !== $variant->name_en) {
                        $variant->forceFill(['name_en' => $name])->save();
                        $edited = true;
                    }
                }
            }
            foreach (['name_en', 'description_en'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = filled($data[$field]) ? trim((string) $data[$field]) : null;
                    $edited = $edited || $data[$field] !== $locked->{$field};
                }
            }
            if ($edited && ! isset($data['translation_mode'])) {
                $data['translation_mode'] = 'manual';
            }
            $backToAi = ($data['translation_mode'] ?? null) === 'ai' && $locked->translation_mode === 'manual';

            AuditLog::saveWithAudit($locked, $data, 'product.updated', $actor);
            $this->syncTranslation($locked, $edited, $backToAi);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function createVariant(Product $product, array $data, ?User $actor): ProductVariant
    {
        return DB::transaction(function () use ($product, $data, $actor) {
            $variant = $product->variants()->create($data);

            AuditLog::record('variant.created', $variant, ['sku' => $variant->sku, 'price' => $variant->price], $actor?->id);
            $this->translations->queue($product);

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
            $this->translations->queue($product);

            return $variant;
        });
    }

    /** @param array<string, mixed> $data */
    public function createDiscount(ProductVariant $variant, array $data, User $actor): VariantDiscount
    {
        return DB::transaction(function () use ($variant, $data, $actor) {
            $discount = $variant->discounts()->create($data)->refresh();

            AuditLog::record('discount.created', $discount, ['sku' => $variant->sku, 'type' => $discount->type, 'value' => $discount->value, 'audience' => $discount->audience], $actor->id);

            return $discount;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDiscount(VariantDiscount $discount, array $data, User $actor): VariantDiscount
    {
        return DB::transaction(function () use ($discount, $data, $actor) {
            AuditLog::saveWithAudit($discount, $data, 'discount.updated', $actor);

            return $discount->refresh();
        });
    }

    public function addImage(Product $product, UploadedFile $file, string $alt, User $actor): ProductImage
    {
        $path = $this->images->storeWebp($file, "products/{$product->id}", 1600);

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

    /**
     * Urutan foto produk; foto pertama menjadi foto utama (dipakai toko). `$ids` harus memuat persis semua foto produk ini,
     * tanpa kurang, lebih, atau ganda, supaya urutan tidak pernah menyisakan foto tanpa posisi.
     *
     * @param  list<int>  $ids
     */
    public function reorderImages(Product $product, array $ids, User $actor): void
    {
        DB::transaction(function () use ($product, $ids, $actor) {
            $current = $product->images()->lockForUpdate()->pluck('id')->all();

            if (count($ids) !== count($current) || array_diff($ids, $current) !== [] || count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'Daftar foto tidak cocok dengan foto produk ini. Muat ulang halaman.']);
            }

            foreach ($ids as $position => $id) {
                ProductImage::whereKey($id)->update(['sort_order' => $position + 1]);
            }

            AuditLog::record('product.images_reordered', $product, ['order' => $ids, 'main_image_id' => $ids[0] ?? null], $actor->id);
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

    /** Terjemahan manual yang baru diedit dianggap mutakhir; mode AI diantre bila sumber Indonesia berubah. */
    private function syncTranslation(Product $product, bool $edited, bool $backToAi): void
    {
        if ($backToAi) {
            $product->forceFill(['translation_hash' => null])->save();
        } elseif ($edited && $product->translation_mode === 'manual') {
            $this->translations->markSynced($product);
        }
        $this->translations->queue($product);
    }

    /** Jenis harus milik kategori yang dipilih; jenis tanpa kategori tidak ada. */
    private function assertTypeFits(?int $categoryId, ?int $typeId): void
    {
        if ($typeId && ! ProductType::whereKey($typeId)->where('product_category_id', $categoryId)->exists()) {
            throw ValidationException::withMessages(['product_type_id' => 'Jenis harus milik kategori yang dipilih.']);
        }
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
