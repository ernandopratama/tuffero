<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    use ApiResponse;

    public function show(CartService $cart): JsonResponse
    {
        return $this->respond($cart);
    }

    public function store(Request $request, CartService $cart): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_QUANTITY],
        ]);

        $sellable = ProductVariant::where('sku', $validated['sku'])
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_published', true))
            ->exists();

        if (! $sellable) {
            throw ValidationException::withMessages(['sku' => 'unavailable']);
        }

        $cart->add($validated['sku'], $validated['quantity']);

        return $this->respond($cart);
    }

    public function update(Request $request, string $sku, CartService $cart): JsonResponse
    {
        $validated = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_QUANTITY]]);

        $cart->set($sku, $validated['quantity']);

        return $this->respond($cart);
    }

    public function select(Request $request, CartService $cart): JsonResponse
    {
        $validated = $request->validate([
            'skus' => ['present', 'array', 'max:200'],
            'skus.*' => ['string', 'max:64'],
        ]);

        $cart->select($validated['skus']);

        return $this->respond($cart);
    }

    public function destroy(string $sku, CartService $cart): JsonResponse
    {
        $cart->remove($sku);

        return $this->respond($cart);
    }

    private function respond(CartService $cart): JsonResponse
    {
        $summary = $cart->summary();

        return $this->success('Cart retrieved.', [
            ...$summary,
            'count' => array_sum(array_column($summary['items'], 'quantity')),
            'items' => array_map(fn (array $item) => [
                ...array_diff_key($item, ['variant_id' => 1, 'available' => 1, 'photo' => 1]),
                'photo' => $item['photo'] ? '/storage/'.$item['photo'] : null,
                // Jumlah stok hanya dibuka saat pembeli perlu mengurangi pesanannya.
                'available' => $item['issue'] === 'insufficient_stock' ? $item['available'] : null,
            ], $summary['items']),
        ]);
    }
}
