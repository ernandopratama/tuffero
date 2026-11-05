<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('settings.manage')
            || ($user->can('settings.manage_contact') && in_array($this->route('setting')->group, Setting::STORE_ADMIN_GROUPS, true));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['value' => Setting::RULES[$this->route('setting')->key] ?? ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'value.required' => 'Nilai wajib diisi.',
            'value.integer' => 'Nilai harus berupa angka bulat.',
            'value.min' => 'Nilai terlalu kecil.',
            'value.max' => 'Nilai terlalu besar.',
            'value.email' => 'Email tidak valid.',
            'value.regex' => 'Gunakan format internasional, contoh +6281234567890.',
            'value.prohibited' => 'Pengaturan ini tidak bisa diubah.',
        ];
    }
}
