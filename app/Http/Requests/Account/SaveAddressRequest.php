<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Store\CheckoutRequest;
use Illuminate\Foundation\Http\FormRequest;

class SaveAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->getRoleNames()->isEmpty();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => CheckoutRequest::normalizePhone($this->input('phone'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+628\d{8,11}$/'],
            'street' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            // Titik rumah dari peta, aturan sama dengan checkout (D-077).
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-11.5,6.5'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:94,141.5'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'label_required',
            'recipient_name.required' => 'name_required',
            'phone.required' => 'phone_invalid',
            'phone.regex' => 'phone_invalid',
            'street.required' => 'street_required',
            'district.required' => 'district_required',
            'city.required' => 'city_required',
            'province.required' => 'province_required',
            'postal_code.required' => 'postal_invalid',
            'postal_code.regex' => 'postal_invalid',
            'latitude.*' => 'pin_invalid',
            'longitude.*' => 'pin_invalid',
        ];
    }
}
