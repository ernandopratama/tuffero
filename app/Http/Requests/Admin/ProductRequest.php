<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
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
            'badges.max' => 'Maksimal 5 lencana.',
            'badges.*.min' => 'Lencana minimal 2 karakter.',
            'badges.*.max' => 'Lencana maksimal 30 karakter.',
            'badges.*.distinct' => 'Lencana tidak boleh sama.',
        ];
    }
}
