<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $post = $this->isMethod('post');

        return [
            'name_id' => [$post ? 'required' : 'sometimes', 'string', 'max:80', $this->uniqueName()],
            'name_en' => [$post ? 'required' : 'sometimes', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', Rule::in(ProductCategory::COLORS)],
        ];
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique('product_categories', 'name_id')->ignore($this->route('category'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name_id.required' => 'Nama dalam bahasa Indonesia wajib diisi.',
            'name_id.max' => 'Nama maksimal 80 karakter.',
            'name_id.unique' => 'Nama ini sudah dipakai.',
            'name_en.required' => 'Nama dalam bahasa Inggris wajib diisi.',
            'name_en.max' => 'Nama maksimal 80 karakter.',
            'color.in' => 'Warna tidak dikenal.',
            'sort_order.integer' => 'Urutan harus berupa angka bulat.',
        ];
    }
}
