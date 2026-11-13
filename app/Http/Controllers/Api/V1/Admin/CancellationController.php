<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderCancellationRequest;
use App\Services\CancellationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CancellationController extends Controller
{
    use ApiResponse;

    public function __construct(private CancellationService $cancellations) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        $page = OrderCancellationRequest::with('order:id,order_number,customer_name,customer_phone,status,payment_status,total')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('request_number', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($order) => $order
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%"))))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->success('Requests retrieved.', [
            'items' => collect($page->items())->map(fn (OrderCancellationRequest $request) => [
                ...$request->only(['id', 'request_number', 'reason', 'channel', 'status', 'due_at', 'rejection_reason', 'created_at']),
                'order_id' => $request->order_id,
                'order_number' => $request->order->order_number,
                'customer_name' => $request->order->customer_name,
                'customer_phone' => $request->order->customer_phone,
                'order_status' => $request->order->status,
                'payment_status' => $request->order->payment_status,
                'total' => $request->order->total,
                // Hanya penanda untuk pengingat; sistem tidak mengubah apa pun saat lewat batas.
                'is_overdue' => in_array($request->status, [...OrderCancellationRequest::OPEN, OrderCancellationRequest::APPROVED], true)
                    && $request->order->payment_status !== 'cancelled'
                    && $request->due_at->isPast(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function validateRequest(Request $request, OrderCancellationRequest $cancellation): JsonResponse
    {
        $this->cancellations->validateRequest($cancellation, $request->user());

        return $this->success('Request updated.');
    }

    public function approveByStore(Request $request, OrderCancellationRequest $cancellation): JsonResponse
    {
        $this->cancellations->approveByStore($cancellation, $request->user());

        return $this->success('Request updated.');
    }

    public function approveByOwner(Request $request, OrderCancellationRequest $cancellation): JsonResponse
    {
        $this->cancellations->approveByOwner($cancellation, $request->user());

        return $this->success('Request updated.');
    }

    public function markRefunded(Request $request, OrderCancellationRequest $cancellation): JsonResponse
    {
        $this->cancellations->markRefunded($cancellation, $request->user());

        return $this->success('Request updated.');
    }

    public function reject(Request $request, OrderCancellationRequest $cancellation): JsonResponse
    {
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Alasan penolakan wajib diisi.', 'reason.max' => 'Alasan maksimal 500 karakter.'],
        );

        $this->cancellations->reject($cancellation, $validated['reason'], $request->user());

        return $this->success('Request updated.');
    }
}
