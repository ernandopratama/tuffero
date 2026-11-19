<?php

namespace App\Http\Requests\Admin;

use App\Models\CaptionGeneration;
use App\Models\MarketingDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CaptionGenerateRequest extends FormRequest
{
    /** Izin dijaga di rute (`marketing_drafts.manage`). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(array_keys(MarketingDraft::PLATFORMS))],
            'tone' => ['nullable', Rule::in(array_keys(CaptionGeneration::TONES))],
            'brief' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.required' => 'Pilih platform.',
            'platform.in' => 'Platform tidak dikenal.',
            'tone.in' => 'Gaya bahasa tidak dikenal.',
            'brief.required' => 'Tulis isi video atau prompt untuk AI.',
            'brief.min' => 'Terlalu pendek. Jelaskan isi video, produk, atau momennya.',
            'brief.max' => 'Maksimal 2000 karakter.',
        ];
    }
}
