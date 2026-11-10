<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_variant_id', 'sku_snapshot', 'product_name_snapshot', 'variant_name_snapshot', 'unit_price', 'quantity', 'weight_gram_snapshot', 'line_total'])]
class OrderItem extends Model
{
    /**
     * Hanya untuk foto di halaman pesanan; nama, harga, dan jumlah tetap dari snapshot. Bisa null bila varian dihapus.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
