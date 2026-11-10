<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'author_name' => ['required', 'string', 'max:100'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'max:1000'],
            'source' => ['sometimes', 'string', 'in:google,whatsapp,instagram,lainnya'],
            'reviewed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'author_name.required' => 'Nama pengulas wajib diisi.',
            'author_name.max' => 'Nama maksimal 100 karakter.',
            'rating.between' => 'Rating harus 1 sampai 5.',
            'body.required' => 'Isi ulasan wajib diisi.',
            'body.max' => 'Isi ulasan maksimal 1000 karakter.',
            'source.in' => 'Sumber tidak dikenal.',
            'reviewed_on.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
        ];
    }
}
