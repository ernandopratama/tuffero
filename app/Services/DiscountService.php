<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\VariantDiscount;
use Illuminate\Support\Facades\Auth;

/**
 * Diskon produk per varian (D-066, D-070, D-071). Harga jual varian tidak diubah; harga efektif dihitung di server.
 * Diskon yang berlaku bersamaan ditumpuk (dijumlah dari harga jual), tetapi total potongan dibatasi persentase
 * `discount.max_percent` dari harga jual (bawaan 50) yang diatur Super Admin dan Admin Toko.
 */
class DiscountService
{
    public const DEFAULT_MAX_PERCENT = 50;

    /** Batas total potongan, persen dari harga jual. */
    public function maxPercent(): int
    {
        $value = (int) Setting::where('key', 'discount.max_percent')->value('value');

        return $value >= 1 && $value <= 90 ? $value : self::DEFAULT_MAX_PERCENT;
    }

    /** Member = pelanggan yang login. Akun staf bukan pelanggan. Tanpa session (API publik) = Non Member. */
    public function memberId(): ?int
    {
        $user = Auth::user();

        return $user && $user->getRoleNames()->isEmpty() ? $user->id : null;
    }

    /**
     * Harga efektif per varian untuk pelanggan ini.
     *
     * @param  iterable<ProductVariant>  $variants
     * @return array<int, int> id varian => harga
     */
    public function prices(iterable $variants, ?int $memberId): array
    {
        $variants = collect($variants)->keyBy('id');
        if ($variants->isEmpty()) {
            return [];
        }

        $now = now();
        $maxPercent = $this->maxPercent();
        $discounts = VariantDiscount::whereIn('product_variant_id', $variants->keys())
            ->where('is_active', true)
            ->whereIn('audience', $memberId ? ['all', 'member'] : ['all', 'guest'])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            // Diskon milik kampanye hanya berlaku selama kampanyenya berjalan.
            ->where(fn ($query) => $query->whereNull('campaign_id')->orWhereHas('campaign', fn ($campaign) => $campaign->live()))
            ->get()
            ->groupBy('product_variant_id');

        return $variants->map(function (ProductVariant $variant) use ($discounts, $maxPercent) {
            $saving = (int) $discounts->get($variant->id, collect())->sum(fn (VariantDiscount $discount) => $this->saving($discount, $variant->price));

            // Dibatasi persentase maksimum, dan harga akhir tidak pernah di bawah Rp1.
            return $variant->price - max(0, min($saving, intdiv($variant->price * $maxPercent, 100), $variant->price - 1));
        })->all();
    }

    /** Potongan rupiah dari satu diskon, sebelum ditumpuk dan dibatasi. */
    public function saving(VariantDiscount $discount, int $price): int
    {
        $amount = $discount->type === 'percent' ? intdiv($price * $discount->value, 100) : $discount->value;

        return max(0, $amount);
    }
}
