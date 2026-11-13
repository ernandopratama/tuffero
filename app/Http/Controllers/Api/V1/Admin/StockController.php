<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustStockRequest;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\DashboardService;
use App\Services\InventoryService;
use App\Traits\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockController extends Controller
{
    use ApiResponse;

    private const EXPORT_LIMIT = 10000;

    private const TYPE_LABELS = [
        'restock' => 'Stok masuk',
        'adjustment' => 'Penyesuaian',
        'reserve' => 'Dipesan',
        'release' => 'Dilepas',
        'sale' => 'Terjual',
        'return' => 'Retur',
    ];

    public function index(Request $request): JsonResponse
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']);
        $warehouseId = $request->integer('warehouse_id') ?: $warehouses->first()?->id;
        $search = $request->string('search')->trim()->toString();
        $defaultLimit = DashboardService::lowStockLimit();

        $page = ProductVariant::with([
            'product:id,name',
            'stocks' => fn ($query) => $query->where('warehouse_id', $warehouseId),
        ])
            ->whereHas('product')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('sku', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->when(in_array($request->query('active'), ['1', '0'], true), fn ($query) => $query->where('is_active', $request->query('active') === '1'))
            // low = tersedia di bawah batas tetapi masih ada; out = habis; alert = keduanya (sama dengan daftar di dasbor).
            // Batas: milik varian bila diisi, jika tidak batas bawaan. Varian nonaktif tidak dihitung, seperti di dasbor.
            ->when(in_array($request->query('level'), ['low', 'out', 'alert'], true), function ($query) use ($request, $warehouseId, $defaultLimit) {
                $available = '(COALESCE((SELECT s.quantity - s.reserved FROM inventory_stocks s WHERE s.product_variant_id = product_variants.id AND s.warehouse_id = ?), 0))';
                $limit = 'COALESCE(product_variants.low_stock_threshold, ?)';

                return match ($request->query('level')) {
                    'out' => $query->where('product_variants.is_active', true)->whereRaw("{$available} <= 0", [$warehouseId]),
                    'low' => $query->where('product_variants.is_active', true)->whereRaw("{$available} > 0 AND {$available} < {$limit}", [$warehouseId, $warehouseId, $defaultLimit]),
                    default => $query->where('product_variants.is_active', true)->whereRaw("({$available} <= 0 OR {$available} < {$limit})", [$warehouseId, $warehouseId, $defaultLimit]),
                };
            })
            ->orderBy('sku')
            ->paginate($this->perPage($request));

        return $this->success('Stock retrieved.', [
            'warehouses' => $warehouses,
            'warehouse_id' => $warehouseId,
            'items' => $page->getCollection()->map(function (ProductVariant $variant) use ($defaultLimit) {
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
                    // Batas menipis yang berlaku: batas varian bila diisi, jika tidak batas bawaan Pengaturan.
                    'low_stock' => $variant->low_stock_threshold ?? $defaultLimit,
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

    /** Riwayat gerakan stok semua varian, dengan filter gudang, jenis, tanggal, dan pencarian SKU atau produk. */
    public function history(Request $request): JsonResponse
    {
        $page = $this->historyQuery($request)->paginate($this->perPage($request));

        return $this->success('Stock history retrieved.', [
            'items' => $page->getCollection()->map(fn ($row) => [
                'id' => $row->id,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'warehouse' => $row->warehouse,
                'sku' => $row->sku,
                'product' => $row->product,
                'variant' => $row->variant,
                'type' => $row->type,
                'quantity_change' => $row->quantity_change,
                'reserved_change' => $row->reserved_change,
                'quantity_after' => $row->quantity_after,
                'reserved_after' => $row->reserved_after,
                'order_number' => $row->order_number,
                'actor' => $row->actor_name,
                'note' => $row->note,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Unduh CSV dengan filter yang sama; maksimal EXPORT_LIMIT baris terbaru. */
    public function exportHistory(Request $request): StreamedResponse
    {
        $query = $this->historyQuery($request)->limit(self::EXPORT_LIMIT);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM supaya Excel membaca UTF-8
            fputcsv($out, ['Waktu', 'Gudang', 'SKU', 'Produk', 'Varian', 'Jenis', 'Perubahan stok', 'Perubahan dipesan', 'Stok setelah', 'Dipesan setelah', 'Pesanan', 'Oleh', 'Catatan']);

            foreach ($query->cursor() as $row) {
                fputcsv($out, [
                    $row->created_at,
                    ...array_map($this->csvCell(...), [$row->warehouse, $row->sku, $row->product, $row->variant, self::TYPE_LABELS[$row->type] ?? $row->type]),
                    $row->quantity_change, $row->reserved_change, $row->quantity_after, $row->reserved_after,
                    ...array_map($this->csvCell(...), [$row->order_number, $row->actor_name, $row->note]),
                ]);
            }

            fclose($out);
        }, 'riwayat-stok-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Sel yang diawali = + - @ dianggap rumus oleh Excel; diberi apostrof di depan (CSV injection). */
    private function csvCell(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function historyQuery(Request $request): Builder
    {
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(array_keys(self::TYPE_LABELS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        // Query builder, bukan model: kolom gabungan (gudang, SKU, produk) bukan atribut StockMovement.
        return DB::table('stock_movements')
            ->join('inventory_stocks', 'inventory_stocks.id', '=', 'stock_movements.inventory_stock_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_stocks.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.actor_user_id')
            ->leftJoin('orders', 'orders.id', '=', 'stock_movements.order_id')
            ->select('stock_movements.*', 'warehouses.name as warehouse', 'product_variants.sku', 'product_variants.name as variant', 'products.name as product', 'users.name as actor_name', 'orders.order_number')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('inventory_stocks.warehouse_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('stock_movements.type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('stock_movements.created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('stock_movements.created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('product_variants.sku', 'like', "%{$search}%")
                ->orWhere('products.name', 'like', "%{$search}%")))
            ->orderByDesc('stock_movements.id');
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
