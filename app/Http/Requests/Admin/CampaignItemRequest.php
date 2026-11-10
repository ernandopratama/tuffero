<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductVariant;
use App\Models\VariantDiscount;

/**
 * Produk dalam kampanye = diskon varian milik kampanye. Aturan nilai sama dengan diskon biasa (batas total di Pengaturan > Diskon);
 * periodenya mengikuti kampanye, jadi tidak ada starts_at dan ends_at sendiri.
 */
class CampaignItemRequest extends DiscountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['starts_at'], $rules['ends_at']);

        if ($this->isMethod('post')) {
            $rules['variant_id'] = ['required', 'integer', 'exists:product_variants,id,deleted_at,NULL'];
        }

        return $rules;
    }

    protected function variantPrice(): int
    {
        $discount = $this->route('discount');
        if ($discount instanceof VariantDiscount) {
            return $discount->variant->price;
        }

        return (int) ProductVariant::whereKey($this->integer('variant_id'))->value('price') ?: 1000000000;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'variant_id.required' => 'Pilih varian produk.', 'variant_id.exists' => 'Varian tidak ditemukan.'];
    }
}
