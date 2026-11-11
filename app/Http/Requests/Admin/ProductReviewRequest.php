<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Produk hanya ditentukan saat membuat; ulasan yang sudah ada tidak berpindah produk.
            'product_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'exists:products,id'],
            'author_name' => ['required', 'string', 'max:100'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Produk wajib dipilih.',
            'product_id.exists' => 'Produk tidak ditemukan.',
            'author_name.required' => 'Nama pengulas wajib diisi.',
            'author_name.max' => 'Nama maksimal 100 karakter.',
            'rating.required' => 'Rating wajib diisi.',
            'rating.between' => 'Rating harus 1 sampai 5.',
            'body.max' => 'Isi ulasan maksimal 1000 karakter.',
        ];
    }
}
