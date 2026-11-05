<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hanya staf. Akun tanpa role (pelanggan) tidak punya profil admin.
        return (bool) $this->user()?->getRoleNames()->isNotEmpty();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            // Email adalah jalur pemulihan akun, jadi mengubahnya butuh password saat ini.
            'current_password' => [Rule::requiredIf(fn () => $this->input('email') !== $user?->email), 'nullable', 'current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...UpdateUserRequest::MESSAGES,
            'current_password.required' => 'Masukkan password saat ini untuk mengganti email.',
            'current_password.current_password' => 'Password saat ini salah.',
        ];
    }
}
