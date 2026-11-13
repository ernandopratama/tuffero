<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['sku', 'name', 'price', 'compare_at_price', 'weight_gram', 'net_weight_gram', 'length_cm', 'width_cm', 'height_cm', 'shelf_life_days', 'max_order_quantity', 'low_stock_threshold', 'is_active'])]
class ProductVariant extends Model
{
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<VariantDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(VariantDiscount::class);
    }

    /** @return HasMany<InventoryStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }
}
