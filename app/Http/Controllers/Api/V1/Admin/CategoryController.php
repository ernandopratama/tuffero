<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Http\Requests\Admin\ProductTypeRequest;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Services\CategoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private CategoryService $categories) {}

    public function index(): JsonResponse
    {
        $items = ProductCategory::withCount('products')
            ->with(['types' => fn ($query) => $query->withCount('products')])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ProductCategory $category) => $this->present($category));

        return $this->success('Categories retrieved.', ['items' => $items]);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        return $this->success('Category created.', $this->present($this->categories->createCategory($request->validated(), $request->user())->loadCount('products')), 201);
    }

    public function update(CategoryRequest $request, ProductCategory $category): JsonResponse
    {
        return $this->success('Category updated.', $this->present($this->categories->updateCategory($category, $request->validated(), $request->user())->loadCount('products')));
    }

    public function storeType(ProductTypeRequest $request, ProductCategory $category): JsonResponse
    {
        $type = $this->categories->createType($category, $request->validated(), $request->user());

        return $this->success('Type created.', $this->presentType($type->loadCount('products')), 201);
    }

    public function updateType(ProductTypeRequest $request, ProductType $type): JsonResponse
    {
        $type = $this->categories->updateType($type, $request->validated(), $request->user());

        return $this->success('Type updated.', $this->presentType($type->loadCount('products')));
    }

    /** @return array<string, mixed> */
    private function present(ProductCategory $category): array
    {
        return [
            ...$category->only(['id', 'slug', 'name_id', 'name_en', 'sort_order', 'is_active', 'color']),
            'products_count' => $category->products_count,
            'types' => $category->relationLoaded('types') ? $category->types->map(fn (ProductType $type) => $this->presentType($type))->values() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentType(ProductType $type): array
    {
        return [...$type->only(['id', 'product_category_id', 'slug', 'name_id', 'name_en', 'sort_order', 'is_active']), 'products_count' => $type->products_count];
    }
}
