<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Services\OrderStatusService;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        $page = Order::query()
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_email', 'like', "%{$search}%")
                ->orWhere('customer_phone', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate(25);

        return $this->paged('Orders retrieved.', $page, fn (Order $order) => [
            ...$order->only(['id', 'order_number', 'customer_name', 'status', 'payment_status', 'shipping_status', 'total', 'created_at']),
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'shipment', 'histories.actor:id,name', 'cancellationRequests']);

        return $this->success('Order retrieved.', [
            ...$order->only([
                'id', 'order_number', 'status', 'payment_status', 'shipping_status', 'customer_name', 'customer_email', 'customer_phone',
                'shipping_address', 'shipping_courier', 'shipping_service', 'subtotal', 'discount_total', 'shipping_fee', 'total',
                'expires_at', 'paid_at', 'cancelled_at', 'created_at',
            ]),
            'items' => $order->items->map($this->presentItem(...)),
            'waybill_id' => $order->shipment?->waybill_id,
            'histories' => $order->histories->sortBy('id')->values()->map(fn (OrderStatusHistory $history) => [
                'status_type' => $history->status_type,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'source' => $history->source,
                'actor' => $history->actor?->name,
                'reason' => $history->reason,
                'created_at' => $history->created_at,
            ]),
            'cancellations' => $order->cancellationRequests->map->only(['request_number', 'status', 'reason', 'created_at']),
        ]);
    }

    /**
     * Data pengemasan: tanpa harga, pembayaran, dan email (D-041).
     */
    public function fulfillment(): JsonResponse
    {
        $page = Order::with(['items', 'shipment'])
            ->whereIn('status', [Order::PAID, Order::PROCESSING])
            ->orderBy('paid_at')
            ->paginate(25);

        return $this->paged('Fulfillment retrieved.', $page, fn (Order $order) => [
            ...$order->only(['id', 'order_number', 'status', 'shipping_status', 'shipping_address', 'shipping_courier', 'shipping_service', 'paid_at']),
            'waybill_id' => $order->shipment?->waybill_id,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'sku' => $item->sku_snapshot,
                'product_name' => $item->product_name_snapshot,
                'variant_name' => $item->variant_name_snapshot,
                'quantity' => $item->quantity,
            ]),
        ]);
    }

    public function shipments(): JsonResponse
    {
        $page = Order::with('shipment')
            ->whereIn('status', [Order::PROCESSING, Order::SHIPPED, Order::COMPLETED])
            ->orderByDesc('id')
            ->paginate(25);

        return $this->paged('Shipments retrieved.', $page, fn (Order $order) => [
            ...$order->only(['id', 'order_number', 'status', 'shipping_status', 'shipping_courier', 'shipping_service']),
            'recipient' => $order->shipping_address['recipient'] ?? null,
            'city' => $order->shipping_address['city'] ?? null,
            'waybill_id' => $order->shipment?->waybill_id,
            'shipped_at' => $order->shipment?->shipped_at,
            'delivered_at' => $order->shipment?->delivered_at,
        ]);
    }

    /**
     * Status pembayaran hanya untuk dilihat (D-041). Tidak ada jalur ubah manual.
     */
    public function payments(): JsonResponse
    {
        $page = Order::query()->orderByDesc('id')->paginate(25);

        return $this->paged('Payments retrieved.', $page, fn (Order $order) => $order->only([
            'id', 'order_number', 'payment_status', 'total', 'paid_at', 'expires_at', 'created_at',
        ]));
    }

    public function startProcessing(Request $request, Order $order, OrderStatusService $status): JsonResponse
    {
        $status->startProcessing($order, $request->user());

        return $this->success('Order updated.');
    }

    public function ship(Request $request, Order $order, OrderStatusService $status): JsonResponse
    {
        $validated = $request->validate(
            ['waybill_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/']],
            ['waybill_id.required' => 'Nomor resi wajib diisi.', 'waybill_id.regex' => 'Nomor resi hanya boleh huruf, angka, dan tanda hubung.', 'waybill_id.max' => 'Nomor resi terlalu panjang.'],
        );

        $status->ship($order, $validated['waybill_id'], $request->user());

        return $this->success('Order updated.');
    }

    public function complete(Request $request, Order $order, OrderStatusService $status): JsonResponse
    {
        $status->complete($order, $request->user());

        return $this->success('Order updated.');
    }

    /** @return array<string, mixed> */
    private function presentItem(OrderItem $item): array
    {
        return [
            'sku' => $item->sku_snapshot,
            'product_name' => $item->product_name_snapshot,
            'variant_name' => $item->variant_name_snapshot,
            'unit_price' => $item->unit_price,
            'quantity' => $item->quantity,
            'line_total' => $item->line_total,
        ];
    }

    /** @param LengthAwarePaginator<int, Order> $page */
    private function paged(string $message, LengthAwarePaginator $page, callable $present): JsonResponse
    {
        return $this->success($message, [
            'items' => collect($page->items())->map($present)->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
