<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Product|null $product Produk yang sudah dihapus (soft delete) tidak ikut dimuat.
 */
#[Fillable(['product_id', 'order_item_id', 'author_name', 'rating', 'body', 'is_published'])]
class ProductReview extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_published' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
