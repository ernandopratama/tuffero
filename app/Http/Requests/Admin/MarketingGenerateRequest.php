<?php

namespace App\Http\Requests\Admin;

use App\Models\MarketingDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketingGenerateRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_keys(MarketingDraft::TYPES))],
            'platform' => ['nullable', Rule::in(array_keys(MarketingDraft::PLATFORMS))],
            'brief' => ['required', 'string', 'min:10', 'max:1000'],
            'scheduled_on' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Jenis tidak dikenal.',
            'platform.in' => 'Platform tidak dikenal.',
            'brief.required' => 'Tulis brief singkat untuk AI.',
            'brief.min' => 'Brief terlalu pendek. Jelaskan produk, momen, atau sudut cerita yang diinginkan.',
            'brief.max' => 'Brief maksimal 1000 karakter.',
        ];
    }
}
