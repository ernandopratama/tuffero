<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Tanpa whereNull deleted_at: SKU varian lama tetap terpakai karena index database unique.
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('product_variants', 'sku')->ignore($this->route('variant'))],
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'compare_at_price' => ['nullable', 'integer', 'gt:price', 'max:1000000000'],
            'weight_gram' => ['required', 'integer', 'min:1', 'max:1000000'],
            'net_weight_gram' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'length_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'width_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'max_order_quantity' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.required' => 'SKU wajib diisi.',
            'sku.regex' => 'SKU hanya boleh huruf, angka, tanda hubung, dan garis bawah.',
            'sku.unique' => 'SKU ini sudah dipakai.',
            'name.required' => 'Nama varian wajib diisi.',
            'price.required' => 'Harga jual wajib diisi.',
            'price.integer' => 'Harga harus angka bulat rupiah.',
            'price.min' => 'Harga jual harus lebih dari 0.',
            'compare_at_price.gt' => 'Harga normal harus lebih besar dari harga jual.',
            'weight_gram.required' => 'Berat paket wajib diisi.',
            'weight_gram.min' => 'Berat paket harus lebih dari 0.',
            '*.integer' => 'Isi dengan angka bulat.',
            '*.min' => 'Nilai terlalu kecil.',
            '*.max' => 'Nilai terlalu besar.',
        ];
    }
}
