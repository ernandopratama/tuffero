<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(['restock', 'adjustment'])],
            'quantity_change' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'note' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'Gudang tidak aktif.',
            'product_variant_id.exists' => 'Varian tidak ditemukan.',
            'quantity_change.required' => 'Jumlah wajib diisi.',
            'quantity_change.integer' => 'Jumlah harus angka bulat.',
            'quantity_change.not_in' => 'Jumlah tidak boleh 0.',
            'note.required' => 'Alasan wajib diisi.',
            'note.max' => 'Alasan maksimal 255 karakter.',
        ];
    }
}
