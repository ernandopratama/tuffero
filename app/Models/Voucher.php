<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property CarbonInterface|null $ends_at
 */
// used_count tidak fillable: hanya boleh berubah lewat penukaran voucher saat checkout.
#[Fillable(['code', 'type', 'value', 'min_subtotal', 'max_discount', 'quota', 'starts_at', 'ends_at', 'is_active'])]
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
        ];
    }

    /** @return MorphMany<AdminComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(AdminComment::class, 'commentable');
    }
}
