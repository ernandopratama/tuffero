<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// slug tidak ikut diubah setelah dibuat: dipakai di tautan ?kategori=.
#[Fillable(['slug', 'name_id', 'name_en', 'sort_order', 'is_active', 'color'])]
class ProductCategory extends Model
{
    /** Palet warna yang boleh dipilih; tampilannya ada di resources/js/utils/categoryColors.js. */
    public const COLORS = ['gold', 'rose', 'orange', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink', 'brown'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<ProductType, $this> */
    public function types(): HasMany
    {
        return $this->hasMany(ProductType::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
