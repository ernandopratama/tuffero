<?php

namespace App\Http\Requests\Admin;

use App\Services\ProfileOtpService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah username sendiri: username baru, saluran OTP, dan kodenya. Username kosong berarti menghapus.
 * Rute .../code (kirim kode) memeriksa username dan saluran lebih dulu, tanpa kode, supaya kode berbiaya tidak terbuang untuk username yang pasti ditolak.
 */
class ChangeUsernameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->getRoleNames()->isNotEmpty();
    }

    protected function prepareForValidation(): void
    {
        $username = $this->input('username');
        $this->merge(['username' => is_string($username) ? (mb_strtolower(trim($username)) ?: null) : null]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'username' => [...UpdateUserRequest::USERNAME_RULES, Rule::unique('users', 'username')->ignore($this->user()?->id)],
            'channel' => ['required', Rule::in(ProfileOtpService::CHANNELS)],
            'code' => ['required', 'string', 'size:6'],
        ];

        if ($this->is('*/code')) {
            unset($rules['code']);
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...UpdateUserRequest::MESSAGES,
            'channel.required' => 'Pilih saluran kode.',
            'channel.in' => 'Saluran tidak dikenal.',
            'code.required' => 'Masukkan kode 6 digit.',
            'code.size' => 'Kode harus 6 digit.',
        ];
    }
}
