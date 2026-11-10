<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_flash' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul kampanye wajib diisi.',
            'starts_at.required' => 'Waktu mulai wajib diisi.',
            'ends_at.required' => 'Waktu selesai wajib diisi.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            '*.date' => 'Tanggal tidak valid.',
        ];
    }
}
