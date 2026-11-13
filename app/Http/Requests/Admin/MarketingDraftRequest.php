<?php

namespace App\Http\Requests\Admin;

use App\Models\MarketingDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketingDraftRequest extends FormRequest
{
    /** Izin dijaga di rute (`marketing_drafts.manage`). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Klaim tren harus membawa sumber dan periode (FR-09).
        $trend = fn () => $this->input('type') === 'trend_report';

        return [
            'type' => ['required', Rule::in(array_keys(MarketingDraft::TYPES))],
            'platform' => ['nullable', Rule::in(array_keys(MarketingDraft::PLATFORMS))],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'sources' => [Rule::requiredIf($trend), 'nullable', 'string', 'max:1000'],
            'source_period' => [Rule::requiredIf($trend), 'nullable', 'string', 'max:100'],
            'scheduled_on' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Jenis tidak dikenal.',
            'platform.in' => 'Platform tidak dikenal.',
            'title.required' => 'Judul wajib diisi.',
            'body.required' => 'Isi wajib diisi.',
            'body.max' => 'Isi maksimal 5000 karakter.',
            'sources.required' => 'Riset tren wajib menyebut sumber.',
            'source_period.required' => 'Riset tren wajib menyebut periode.',
        ];
    }
}
