<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class ChangeAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->getRoleNames()->isEmpty();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'current_required',
            'current_password.current_password' => 'current_wrong',
            'password.required' => 'password_too_short',
            'password.min' => 'password_too_short',
            'password.max' => 'password_too_long',
            'password.confirmed' => 'password_mismatch',
            'password.different' => 'password_same',
        ];
    }
}
