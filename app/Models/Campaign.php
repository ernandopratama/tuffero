<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 */
// image_path tidak fillable: hanya berubah lewat unggah gambar.
#[Fillable(['title', 'description', 'starts_at', 'ends_at', 'is_flash', 'is_active'])]
class Campaign extends Model
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
            'is_flash' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Kampanye yang sedang berjalan: aktif dan dalam periodenya. */
    public function scopeLive(Builder $query): void
    {
        $query->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>=', now());
    }

    /** Sedang berjalan atau akan datang: yang tampil di halaman Promo. */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_active', true)->where('ends_at', '>=', now());
    }

    /** @return HasMany<VariantDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(VariantDiscount::class);
    }

    /** @return HasMany<Voucher, $this> */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
