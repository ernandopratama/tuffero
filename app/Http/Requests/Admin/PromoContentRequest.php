<?php

namespace App\Http\Requests\Admin;

use App\Rules\AllowedVideoUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $content = $this->route('content');
        $type = $content->type ?? $this->input('type');

        return [
            // Jenis tidak bisa diganti setelah dibuat.
            'type' => $content ? ['prohibited'] : ['required', Rule::in(['banner', 'text', 'video'])],
            'title' => ['required', 'string', 'max:150'],
            'body' => [$type === 'text' ? 'required' : 'nullable', 'string', 'max:5000'],
            'video_url' => $type === 'video' ? ['required', 'string', 'max:255', new AllowedVideoUrl] : ['prohibited'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => 'Pilih jenis konten.',
            'title.required' => 'Judul wajib diisi.',
            'title.max' => 'Judul maksimal 150 karakter.',
            'body.required' => 'Teks promo wajib diisi.',
            'body.max' => 'Teks maksimal 5000 karakter.',
            'video_url.required' => 'Tautan video wajib diisi.',
            'video_url.max' => 'Tautan terlalu panjang.',
            'sort_order.between' => 'Urutan antara 0 dan 9999.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            '*.date' => 'Tanggal tidak valid.',
        ];
    }
}
