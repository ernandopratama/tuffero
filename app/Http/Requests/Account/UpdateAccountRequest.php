<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->getRoleNames()->isEmpty();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'name_required',
            'name.string' => 'name_required',
            'name.max' => 'name_too_long',
        ];
    }
}
