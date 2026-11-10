<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'customer_email' => is_string($this->input('customer_email')) ? strtolower(trim($this->input('customer_email'))) : null,
            'customer_phone' => is_string($this->input('customer_phone')) ? self::normalizePhone($this->input('customer_phone')) : null,
        ]));
    }

    /** Nomor seluler Indonesia menjadi format +628xxx supaya mudah dicocokkan. */
    public static function normalizePhone(string $phone): string
    {
        return '+62'.preg_replace('/^(62|0)/', '', preg_replace('/\D/', '', $phone));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:190'],
            'customer_phone' => ['required', 'string', 'regex:/^\+628\d{8,11}$/'],
            'address' => ['required', 'array'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.district' => ['required', 'string', 'max:100'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.province' => ['required', 'string', 'max:100'],
            'address.postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            // Titik rumah dari peta checkout, opsional. Batas kasar wilayah Indonesia supaya titik asal-asalan ditolak.
            'address.latitude' => ['nullable', 'required_with:address.longitude', 'numeric', 'between:-11.5,6.5'],
            'address.longitude' => ['nullable', 'required_with:address.latitude', 'numeric', 'between:94,141.5'],
            'courier' => ['required', 'string', 'max:30'],
            'service' => ['required', 'string', 'max:50'],
            'voucher_code' => ['nullable', 'string', 'max:40'],
            // Maksimal 2 voucher per pesanan (D-078); server yang memutuskan boleh tidaknya digabung.
            'voucher_codes' => ['nullable', 'array', 'max:2'],
            'voucher_codes.*' => ['string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::MESSAGES;
    }

    /** @var array<string, string> */
    public const MESSAGES = [
        'customer_name.required' => 'name_required',
        'customer_email.required' => 'email_invalid',
        'customer_email.email' => 'email_invalid',
        'customer_phone.required' => 'phone_invalid',
        'customer_phone.regex' => 'phone_invalid',
        'address.street.required' => 'street_required',
        'address.district.required' => 'district_required',
        'address.city.required' => 'city_required',
        'address.province.required' => 'province_required',
        'address.postal_code.required' => 'postal_invalid',
        'address.postal_code.regex' => 'postal_invalid',
        'address.latitude.*' => 'pin_invalid',
        'address.longitude.*' => 'pin_invalid',
        'courier.required' => 'courier_required',
        'service.required' => 'courier_required',
    ];
}
