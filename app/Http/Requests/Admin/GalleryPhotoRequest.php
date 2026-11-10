<?php

namespace App\Http\Requests\Admin;

use App\Models\GalleryPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Services\ImageService;

class GalleryPhotoRequest extends FormRequest
{
    /** Izin dijaga di rute (`content.manage`). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'title_en' => ['nullable', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(GalleryPhoto::CATEGORIES))],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
            // Foto wajib saat membuat; mengganti foto lewat endpoint tersendiri. Tanpa SVG: berkas itu bisa memuat skrip.
            'image' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'file', ImageService::MIMES, 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'Pilih foto dulu.',
            'image.mimes' => ImageService::MIME_MESSAGE,
            'image.max' => 'Ukuran foto maksimal 5 MB.',
            'image.dimensions' => 'Dimensi foto maksimal 6000 × 6000 piksel.',
            'category.in' => 'Kategori tidak dikenal.',
        ];
    }
}
