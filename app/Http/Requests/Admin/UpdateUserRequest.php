<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public const MESSAGES = [
        'name.required' => 'Nama wajib diisi.',
        'email.required' => 'Email wajib diisi.',
        'email.email' => 'Email tidak valid.',
        'email.unique' => 'Email ini sudah dipakai akun lain.',
        'password.required' => 'Password wajib diisi.',
        'password.min' => 'Password minimal 12 karakter.',
        'password.mixed' => 'Password harus memuat huruf besar dan huruf kecil.',
        'password.numbers' => 'Password harus memuat angka.',
        'role.required' => 'Role wajib dipilih.',
        'role.exists' => 'Role tidak dikenal.',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return ! ($this->has('role') && ! $user?->can('roles.manage'))
            && ! ($this->has('mfa_required') && ! $user?->can('users.manage_mfa'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['sometimes', 'nullable', 'string', Password::min(12)->mixedCase()->numbers()],
            'role' => ['sometimes', 'required', 'string', Rule::exists('roles', 'name')],
            'is_active' => ['sometimes', 'boolean'],
            'mfa_required' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::MESSAGES;
    }
}
