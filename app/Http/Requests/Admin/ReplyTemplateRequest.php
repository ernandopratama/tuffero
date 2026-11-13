<?php

namespace App\Http\Requests\Admin;

use App\Models\ReplyTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplyTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:120'],
            'body' => [$required, 'string', 'max:1000'],
            'scope' => ['sometimes', Rule::in(ReplyTemplate::SCOPES)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'title.max' => 'Judul maksimal 120 karakter.',
            'body.required' => 'Isi template wajib diisi.',
            'body.max' => 'Isi template maksimal 1000 karakter.',
            'scope.in' => 'Tempat pemakaian tidak valid.',
            'sort_order.integer' => 'Urutan harus berupa angka bulat.',
        ];
    }
}
