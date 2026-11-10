<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductVariant;
use App\Models\VariantDiscount;
use App\Services\DiscountService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $price = $this->variantPrice();
        $cap = app(DiscountService::class)->maxPercent();

        return [
            'audience' => ['required', Rule::in(['all', 'guest', 'member'])],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            // Satu diskon tidak boleh melewati batas total (Pengaturan > Diskon); diskon bertumpuk dibatasi lagi saat harga dihitung.
            'value' => ['required', 'integer', 'min:1', $this->input('type') === 'percent' ? 'max:'.$cap : 'max:'.intdiv($price * $cap, 100)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function variantPrice(): int
    {
        $routeVariant = $this->route('variant');
        $routeDiscount = $this->route('discount');

        return $routeVariant instanceof ProductVariant ? $routeVariant->price : ($routeDiscount instanceof VariantDiscount ? $routeDiscount->variant->price : 1000000000);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $cap = app(DiscountService::class)->maxPercent();

        return [
            'audience.in' => 'Pilih untuk siapa diskon ini.',
            'type.in' => 'Pilih jenis diskon.',
            'value.required' => 'Nilai diskon wajib diisi.',
            'value.min' => 'Nilai diskon harus lebih dari 0.',
            'value.max' => "Melebihi batas diskon {$cap}% dari harga jual. Batas diatur di Pengaturan > Diskon.",
            '*.integer' => 'Isi dengan angka bulat.',
            '*.date' => 'Tanggal tidak valid.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
        ];
    }
}
