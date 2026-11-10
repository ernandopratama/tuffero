<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 */
// used_count tidak fillable: hanya boleh berubah lewat penukaran voucher saat checkout.
#[Fillable(['code', 'description', 'type', 'audience', 'value', 'min_subtotal', 'max_discount', 'quota', 'per_customer_limit', 'starts_at', 'ends_at', 'is_active', 'is_listed', 'requires_claim', 'stackable', 'campaign_id'])]
class Voucher extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'is_listed' => 'boolean',
            'requires_claim' => 'boolean',
            'stackable' => 'boolean',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<VoucherClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(VoucherClaim::class);
    }

    /** @return MorphMany<AdminComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(AdminComment::class, 'commentable');
    }
}
