<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isPercent = $this->input('type') === 'percent';

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('vouchers', 'code')->ignore($this->route('voucher'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:150'],
            'type' => ['required', Rule::in(['fixed', 'percent'])],
            // all = semua, guest = Non Member (tanpa login), member = pelanggan yang login (D-066).
            // Voucher yang perlu diklaim butuh akun, jadi tidak boleh khusus Non Member (D-072).
            'audience' => ['sometimes', Rule::in($this->boolean('requires_claim') ? ['all', 'member'] : ['all', 'guest', 'member'])],
            'requires_claim' => ['sometimes', 'boolean'],
            'is_listed' => ['sometimes', 'boolean'],
            // Boleh dipakai bersama voucher lain dalam satu pesanan (maksimal 2, D-078).
            'stackable' => ['sometimes', 'boolean'],
            // Voucher kampanye hanya berlaku selama kampanyenya berjalan (D-073).
            'campaign_id' => ['sometimes', 'nullable', 'integer', 'exists:campaigns,id'],
            'per_customer_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'value' => ['required', 'integer', 'min:1', $isPercent ? 'max:100' : 'max:1000000000'],
            'min_subtotal' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'max_discount' => [$isPercent ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:1000000000'],
            // Kuota tidak boleh lebih kecil dari jumlah yang sudah terpakai.
            'quota' => ['nullable', 'integer', 'min:'.max(1, $this->route('voucher')->used_count ?? 1), 'max:100000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'audience.in' => 'Voucher yang perlu diklaim hanya untuk Semua pelanggan atau Member.',
            'code.required' => 'Kode voucher wajib diisi.',
            'code.regex' => 'Kode 3 sampai 40 karakter: huruf, angka, tanda hubung, atau garis bawah.',
            'code.unique' => 'Kode ini sudah dipakai.',
            'value.required' => 'Nilai diskon wajib diisi.',
            'value.min' => 'Nilai diskon harus lebih dari 0.',
            'value.max' => 'Nilai diskon terlalu besar. Persen maksimal 100.',
            'min_subtotal.required' => 'Minimum belanja wajib diisi. Isi 0 bila tidak ada.',
            'max_discount.prohibited' => 'Batas diskon hanya untuk voucher persen.',
            'quota.min' => 'Kuota tidak boleh lebih kecil dari jumlah yang sudah terpakai.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            '*.integer' => 'Isi dengan angka bulat.',
            '*.date' => 'Tanggal tidak valid.',
        ];
    }
}
