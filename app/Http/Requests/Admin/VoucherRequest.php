<?php

namespace App\Http\Requests\Admin;

use App\Models\Voucher;
use Illuminate\Contracts\Validation\Validator;
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

        // D-126: gratis ongkir = potongan tetap, khusus member. Hadiah pendaftaran selalu lewat klaim otomatis dan tidak tampil di daftar umum.
        if ($this->input('kind') === Voucher::KIND_SHIPPING) {
            $this->merge(['type' => 'fixed', 'audience' => 'member', 'max_discount' => null]);
        } else {
            $this->merge(['provinces' => null, 'budget' => null, 'signup_grant' => false, 'claim_valid_days' => null]);
        }
        if ($this->boolean('signup_grant')) {
            $this->merge(['requires_claim' => true, 'is_listed' => false]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isPercent = $this->input('type') === 'percent';
        $isShipping = $this->input('kind') === Voucher::KIND_SHIPPING;

        return [
            'kind' => ['sometimes', Rule::in([Voucher::KIND_ORDER, Voucher::KIND_SHIPPING])],
            'provinces' => ['nullable', 'array', 'max:40'],
            'provinces.*' => ['string', 'max:100', 'distinct'],
            'budget' => ['nullable', 'integer', 'min:1000', 'max:10000000000'],
            'limit_period' => ['sometimes', 'nullable', Rule::in(['month'])],
            'signup_grant' => ['sometimes', 'boolean'],
            'claim_valid_days' => [$this->boolean('signup_grant') ? 'required' : 'nullable', 'integer', 'min:1', 'max:365'],
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
            'campaign_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:campaigns,public_id'],
            'per_customer_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'value' => $isShipping
                ? ['required', 'integer', 'min:'.Voucher::SHIPPING_MIN, 'max:'.Voucher::SHIPPING_MAX]
                : ['required', 'integer', 'min:1', $isPercent ? 'max:100' : 'max:1000000000'],
            'min_subtotal' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'max_discount' => $isPercent ? ['nullable', 'integer', 'min:1', 'max:1000000000'] : ['nullable', 'prohibited', 'integer', 'min:1', 'max:1000000000'],
            // Kuota tidak boleh lebih kecil dari jumlah yang sudah terpakai.
            'quota' => ['nullable', 'integer', 'min:'.max(1, $this->route('voucher')->used_count ?? 1), 'max:100000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * D-147: voucher yang bisa dipakai Non Member (Semua atau Non Member, tanpa klaim) wajib punya batas pakai per pelanggan.
     * Kosong berarti tanpa batas, dan itu hanya boleh untuk voucher khusus member. Voucher yang dimatikan tidak diperiksa,
     * supaya voucher lama tanpa batas tetap bisa dinonaktifkan.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $voucher = $this->route('voucher');
            $audience = $this->input('audience', $voucher->audience ?? 'all');
            $kind = $this->input('kind', $voucher->kind ?? Voucher::KIND_ORDER);
            $claim = $this->exists('requires_claim') ? $this->boolean('requires_claim') : (bool) ($voucher->requires_claim ?? false);
            $limit = $this->exists('per_customer_limit') ? $this->input('per_customer_limit') : $voucher?->per_customer_limit;

            if ($this->boolean('is_active') && in_array($audience, ['all', 'guest'], true) && $kind !== Voucher::KIND_SHIPPING && ! $claim && blank($limit)) {
                $validator->errors()->add('per_customer_limit', 'Voucher yang bisa dipakai Non Member wajib punya batas pakai per pelanggan (minimal 1).');
            }
        }];
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
            'value.min' => $this->input('kind') === Voucher::KIND_SHIPPING ? 'Potongan gratis ongkir minimal Rp1.000.' : 'Nilai diskon harus lebih dari 0.',
            'value.max' => $this->input('kind') === Voucher::KIND_SHIPPING ? 'Potongan gratis ongkir maksimal Rp25.000.' : 'Nilai diskon terlalu besar. Persen maksimal 100.',
            'budget.min' => 'Anggaran minimal Rp1.000.',
            'claim_valid_days.required' => 'Isi masa berlaku (hari) untuk voucher hadiah pendaftaran.',
            'provinces.*.distinct' => 'Provinsi tidak boleh dobel.',
            'min_subtotal.required' => 'Minimum belanja wajib diisi. Isi 0 bila tidak ada.',
            'max_discount.prohibited' => 'Batas diskon hanya untuk voucher persen.',
            'quota.min' => 'Kuota tidak boleh lebih kecil dari jumlah yang sudah terpakai.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            '*.integer' => 'Isi dengan angka bulat.',
            '*.date' => 'Tanggal tidak valid.',
        ];
    }
}
