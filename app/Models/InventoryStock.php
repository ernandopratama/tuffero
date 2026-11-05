<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['warehouse_id', 'product_variant_id', 'quantity', 'reserved'])]
class InventoryStock extends Model
{
    /**
     * Stok terbesar yang tersedia di satu gudang aktif, karena satu pesanan hanya dari satu gudang (BR-06).
     *
     * @param  Collection<int, int>  $variantIds
     * @return array<int, int>
     */
    public static function availableByVariant(Collection $variantIds): array
    {
        return static::whereIn('product_variant_id', $variantIds)
            ->whereIn('warehouse_id', Warehouse::where('is_active', true)->select('id'))
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn (Collection $stocks) => (int) $stocks->max(fn (InventoryStock $stock) => $stock->quantity - $stock->reserved))
            ->all();
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
