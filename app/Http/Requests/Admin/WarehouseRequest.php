<?php

namespace App\Http\Requests\Admin;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Ubah data gudang. Kode gudang tidak bisa diubah dan tidak ada tambah atau hapus (D-008). */
class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'city' => ['sometimes', 'string', 'max:80'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'postal_code' => ['sometimes', 'nullable', 'digits:5'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'phone' => ['sometimes', 'nullable', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Warehouse $warehouse */
            $warehouse = $this->route('warehouse');

            // Checkout memilih gudang aktif; tanpa satu pun, semua checkout ditolak.
            if ($this->has('is_active') && ! $this->boolean('is_active') && $warehouse->is_active
                && ! Warehouse::where('is_active', true)->whereKeyNot($warehouse->id)->exists()) {
                $validator->errors()->add('is_active', 'Minimal satu gudang harus aktif, kalau tidak checkout tidak bisa memilih gudang.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.max' => 'Nama maksimal 100 karakter.',
            'city.max' => 'Kota maksimal 80 karakter.',
            'address.max' => 'Alamat maksimal 500 karakter.',
            'postal_code.digits' => 'Kode pos harus 5 angka.',
            'latitude.between' => 'Lintang harus di antara -90 dan 90.',
            'longitude.between' => 'Bujur harus di antara -180 dan 180.',
            'phone.regex' => 'Nomor HP tidak valid.',
        ];
    }
}
