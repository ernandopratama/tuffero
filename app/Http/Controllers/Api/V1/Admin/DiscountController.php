<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DiscountRequest;
use App\Models\ProductVariant;
use App\Models\VariantDiscount;
use App\Services\ProductService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DiscountController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductService $products) {}

    public function store(DiscountRequest $request, ProductVariant $variant): JsonResponse
    {
        return $this->success('Discount created.', $this->products->createDiscount($variant, $request->validated(), $request->user()), 201);
    }

    public function update(DiscountRequest $request, VariantDiscount $discount): JsonResponse
    {
        return $this->success('Discount updated.', $this->products->updateDiscount($discount, $request->validated(), $request->user()));
    }
}
