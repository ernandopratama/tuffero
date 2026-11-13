<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran pelanggan. Pesan galat berupa kode (bukan kalimat) supaya frontend memakai teks terjemahannya sendiri.
 * Rute send membawa data pendaftaran; verify hanya membawa kode, data lainnya diambil dari sesi.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $phone = $this->input('phone');
        $this->merge([
            'email' => is_string($email) ? mb_strtolower(trim($email)) : null,
            'phone' => is_string($phone) && preg_replace('/\D/', '', $phone) !== '' ? CheckoutRequest::normalizePhone($phone) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->is('*/verify')) {
            return ['code' => ['required', 'string', 'digits:6']];
        }

        if ($this->is('*/resend')) {
            return ['captcha_token' => ['nullable', 'string', 'max:2048']];
        }

        return [
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+628\d{8,11}$/'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'channel' => ['required', 'in:email,phone'],
            'captcha_token' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.*' => 'email_invalid',
            'phone.regex' => 'phone_invalid',
            'password.*' => 'password_invalid',
            'channel.*' => 'channel_invalid',
            'code.*' => 'otp_invalid',
        ];
    }

    /** Saluran WhatsApp tanpa nomor tidak bisa dikirimi kode. */
    protected function passedValidation(): void
    {
        if ($this->input('channel') === 'phone' && ! $this->input('phone')) {
            throw ValidationException::withMessages(['phone' => 'phone_invalid']);
        }
    }
}
