<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Store\CheckoutRequest;
use App\Services\MfaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hanya staf. Akun tanpa role (pelanggan) tidak punya profil admin.
        return (bool) $this->user()?->getRoleNames()->isNotEmpty();
    }

    /** Nomor HP diseragamkan ke +628xxx (sama dengan pesanan) sebelum divalidasi; kosong berarti menghapus nomor. */
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $phone = $this->input('phone');
            $this->merge(['phone' => is_string($phone) && trim($phone) !== '' ? CheckoutRequest::normalizePhone($phone) : null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $sensitive = $this->changesSensitiveData();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+628\d{8,11}$/', Rule::unique('users', 'phone')->ignore($user?->id)],
            // Email dan nomor HP adalah jalur pemulihan akun, jadi mengubahnya butuh password saat ini.
            'current_password' => [Rule::requiredIf($sensitive), 'nullable', 'current_password'],
            'mfa_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    /** Email atau nomor HP berubah dari yang tersimpan. */
    public function changesSensitiveData(): bool
    {
        $user = $this->user();

        if ($this->input('email') !== $user?->email) {
            return true;
        }

        return $this->has('phone') && ($this->input('phone') ?: null) !== $user?->phone;
    }

    /**
     * Akun dengan MFA aktif wajib menyertakan kode autentikator 6 digit untuk mengganti email atau nomor HP.
     * Diperiksa terakhir (setelah aturan lain lolos) karena memeriksa kode memakainya: kode yang sama tidak bisa dipakai dua kali.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $user = $this->user();

            if ($validator->errors()->isNotEmpty() || ! $this->changesSensitiveData() || ! $user?->mfa_totp_confirmed_at || ! is_string($user->mfa_totp_secret)) {
                return;
            }

            $code = preg_replace('/\s+/', '', (string) $this->input('mfa_code'));

            if ($code === '') {
                $validator->errors()->add('mfa_code', 'Masukkan kode autentikator untuk mengganti email atau nomor HP.');
            } elseif (! app(MfaService::class)->verifyCode($user, $code)) {
                $validator->errors()->add('mfa_code', 'Kode autentikator salah atau sudah dipakai.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...UpdateUserRequest::MESSAGES,
            'phone.regex' => 'Nomor HP harus nomor seluler Indonesia, contoh 081234567890.',
            'phone.unique' => 'Nomor HP ini sudah dipakai akun lain.',
            'current_password.required' => 'Masukkan password saat ini untuk mengganti email atau nomor HP.',
            'current_password.current_password' => 'Password saat ini salah.',
        ];
    }
}
