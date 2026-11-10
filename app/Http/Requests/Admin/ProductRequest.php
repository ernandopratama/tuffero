<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'required', 'string', 'max:150'],
            'product_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_categories', 'id')->where('is_active', true)],
            'product_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_types', 'id')->where('is_active', true)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:150'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'translation_mode' => ['sometimes', Rule::in(['ai', 'manual'])],
            'variant_names_en' => ['sometimes', 'array', 'max:50'],
            'variant_names_en.*' => ['nullable', 'string', 'max:100'],
            'badges' => ['sometimes', 'nullable', 'array', 'max:5'],
            'badges.*' => ['string', 'distinct', 'min:2', 'max:30'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama produk wajib diisi.',
            'name.max' => 'Nama produk maksimal 150 karakter.',
            'description.max' => 'Deskripsi maksimal 5000 karakter.',
            'name_en.max' => 'Nama Inggris maksimal 150 karakter.',
            'description_en.max' => 'Deskripsi Inggris maksimal 5000 karakter.',
            'product_category_id.exists' => 'Kategori tidak ditemukan atau tidak aktif.',
            'product_type_id.exists' => 'Jenis tidak ditemukan atau tidak aktif.',
            'badges.max' => 'Maksimal 5 lencana.',
            'badges.*.min' => 'Lencana minimal 2 karakter.',
            'badges.*.max' => 'Lencana maksimal 30 karakter.',
            'badges.*.distinct' => 'Lencana tidak boleh sama.',
        ];
    }
}
