<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('roles.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username'))) ?: null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => [...UpdateUserRequest::USERNAME_RULES, 'unique:users,username'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return UpdateUserRequest::MESSAGES;
    }
}
