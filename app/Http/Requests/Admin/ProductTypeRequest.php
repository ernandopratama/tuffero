<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductCategory;
use App\Models\ProductType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/** Jenis: aturan sama dengan kategori, tetapi nama hanya harus unik di dalam kategorinya. */
class ProductTypeRequest extends CategoryRequest
{
    protected function uniqueName(): Unique
    {
        // Tambah jenis: kategori ada di alamat. Ubah jenis: kategorinya dari jenis itu sendiri.
        $category = $this->route('category');
        $type = $this->route('type');
        $categoryId = $category instanceof ProductCategory ? $category->id : ($type instanceof ProductType ? $type->product_category_id : null);

        return Rule::unique('product_types', 'name_id')->where('product_category_id', $categoryId)->ignore($this->route('type'));
    }
}
