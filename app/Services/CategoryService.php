<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kategori dan jenis produk. Tidak ada hapus: kategori dan jenis yang tak dipakai lagi dinonaktifkan,
 * dan tidak boleh dinonaktifkan selama masih ada produk tayang di dalamnya (produk tidak boleh "hilang" dari filter toko).
 */
class CategoryService
{
    /** @param array<string, mixed> $data */
    public function createCategory(array $data, User $actor): ProductCategory
    {
        return DB::transaction(function () use ($data, $actor) {
            $category = ProductCategory::create([
                'slug' => $this->uniqueSlug($data['name_id'], 'product_categories'),
                'name_id' => $data['name_id'],
                'name_en' => $data['name_en'],
                'sort_order' => $data['sort_order'] ?? (int) ProductCategory::max('sort_order') + 1,
                'is_active' => $data['is_active'] ?? true,
                'color' => $data['color'] ?? null,
            ])->refresh();

            AuditLog::record('category.created', $category, ['name_id' => $category->name_id], $actor->id);

            return $category;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(ProductCategory $category, array $data, User $actor): ProductCategory
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            if (($data['is_active'] ?? true) === false && $category->products()->where('is_published', true)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Masih ada produk tayang di kategori ini. Pindahkan atau tarik produknya dulu.']);
            }

            AuditLog::saveWithAudit($category, $data, 'category.updated', $actor);

            return $category->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function createType(ProductCategory $category, array $data, User $actor): ProductType
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            $type = $category->types()->create([
                'slug' => $this->uniqueSlug($data['name_id'], 'product_types'),
                'name_id' => $data['name_id'],
                'name_en' => $data['name_en'],
                'sort_order' => $data['sort_order'] ?? (int) $category->types()->max('sort_order') + 1,
                'is_active' => $data['is_active'] ?? true,
            ])->refresh();

            AuditLog::record('category.type_created', $type, ['category' => $category->slug, 'name_id' => $type->name_id], $actor->id);

            return $type;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateType(ProductType $type, array $data, User $actor): ProductType
    {
        return DB::transaction(function () use ($type, $data, $actor) {
            if (($data['is_active'] ?? true) === false && $type->products()->where('is_published', true)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Masih ada produk tayang dengan jenis ini. Pindahkan atau tarik produknya dulu.']);
            }

            AuditLog::saveWithAudit($type, $data, 'category.type_updated', $actor);

            return $type->refresh();
        });
    }

    private function uniqueSlug(string $name, string $table): string
    {
        $base = Str::slug($name) ?: 'kategori';
        $slug = $base;

        for ($i = 2; DB::table($table)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
