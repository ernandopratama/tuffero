<?php

namespace App\Http\Requests\Store;

use App\Services\RegistrationService;
use Illuminate\Foundation\Http\FormRequest;

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
            // Saklar WhatsApp mati (D-146): nomor tidak diminta dan diabaikan.
            'phone' => RegistrationService::whatsappRequired() && is_string($phone) && preg_replace('/\D/', '', $phone) !== '' ? CheckoutRequest::normalizePhone($phone) : null,
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

        // Saklar WhatsApp mati (D-146): hanya saluran email, jadi tidak ada pesan WhatsApp berbiaya yang bisa diminta.
        $whatsapp = RegistrationService::whatsappRequired();
        $channel = ['required', $whatsapp ? 'in:email,phone' : 'in:email'];

        // Saluran berikutnya setelah satu saluran terverifikasi: data lain diambil dari sesi.
        if ($this->is('*/channel')) {
            return ['channel' => $channel, 'captcha_token' => ['nullable', 'string', 'max:2048']];
        }

        return [
            'email' => ['required', 'email', 'max:255'],
            'phone' => $whatsapp ? ['required', 'string', 'regex:/^\+628\d{8,11}$/'] : ['nullable'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'channel' => $channel,
            'captcha_token' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.*' => 'email_invalid',
            'phone.*' => 'phone_invalid',
            'password.*' => 'password_invalid',
            'channel.*' => 'channel_invalid',
            'code.*' => 'otp_invalid',
        ];
    }
}
