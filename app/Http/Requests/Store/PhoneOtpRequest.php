<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class PhoneOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        $this->merge(['phone' => is_string($phone) ? CheckoutRequest::normalizePhone($phone) : null]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+628\d{8,11}$/'],
            'resend' => ['sometimes', 'boolean'],
            'probe' => ['sometimes', 'boolean'],
            'captcha_token' => ['nullable', 'string', 'max:2048'],
            'code' => [$this->is('*/verify') ? 'required' : 'prohibited', 'string', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.required' => 'phone_invalid',
            'phone.regex' => 'phone_invalid',
            'code.required' => 'otp_invalid',
            'code.digits' => 'otp_invalid',
        ];
    }
}
