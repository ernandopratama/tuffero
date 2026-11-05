<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustStockRequest;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']);
        $warehouseId = $request->integer('warehouse_id') ?: $warehouses->first()?->id;
        $search = $request->string('search')->trim()->toString();

        $page = ProductVariant::with([
            'product:id,name',
            'stocks' => fn ($query) => $query->where('warehouse_id', $warehouseId),
        ])
            ->whereHas('product')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('sku', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->orderBy('sku')
            ->paginate(25);

        return $this->success('Stock retrieved.', [
            'warehouses' => $warehouses,
            'warehouse_id' => $warehouseId,
            'items' => $page->getCollection()->map(function (ProductVariant $variant) {
                $stock = $variant->stocks->first();

                return [
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'product' => $variant->product->name,
                    'variant' => $variant->name,
                    'is_active' => $variant->is_active,
                    'quantity' => $stock->quantity ?? 0,
                    'reserved' => $stock->reserved ?? 0,
                    'available' => ($stock->quantity ?? 0) - ($stock->reserved ?? 0),
                ];
            })->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'variant_id' => ['required', 'integer'],
        ]);

        $stock = InventoryStock::where(['warehouse_id' => $validated['warehouse_id'], 'product_variant_id' => $validated['variant_id']])->first();

        $items = $stock
            ? $stock->movements()->with('actor:id,name')->orderByDesc('id')->limit(50)->get()->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity_change' => $movement->quantity_change,
                'quantity_after' => $movement->quantity_after,
                'note' => $movement->note,
                'actor' => $movement->actor?->name,
                'created_at' => $movement->created_at,
            ])
            : [];

        return $this->success('Stock movements retrieved.', ['items' => $items]);
    }

    public function adjust(AdjustStockRequest $request, InventoryService $inventory): JsonResponse
    {
        $validated = $request->validated();

        $stock = $inventory->adjust(
            $validated['warehouse_id'],
            $validated['product_variant_id'],
            $validated['type'],
            $validated['quantity_change'],
            $validated['note'],
            $request->user(),
        );

        return $this->success('Stock adjusted.', [
            'quantity' => $stock->quantity,
            'reserved' => $stock->reserved,
            'available' => $stock->quantity - $stock->reserved,
        ]);
    }
}
