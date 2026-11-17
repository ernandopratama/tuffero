<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderReviewService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tinjauan pesanan (D-121, D-132). Antrean dibaca Admin Toko, Pemilik, dan Super Admin (`orders.review_alert`);
 * menyetujui, menolak, dan menandai sudah ditinjau hanya Admin Toko dan Super Admin (`orders.review`).
 */
class OrderReviewController extends Controller
{
    use ApiResponse;

    /** Data untuk peringatan mendesak di pojok kanan: pesanan menunggu persetujuan dan jumlah pesanan bertanda yang belum ditinjau. */
    public function queue(): JsonResponse
    {
        $awaiting = Order::where('status', Order::AWAITING_APPROVAL)->orderBy('review_due_at')->limit(20)
            ->get(['id', 'order_number', 'customer_name', 'total', 'review_due_at', 'created_at']);
        $flagged = Order::awaitingReview()->where('status', '!=', Order::AWAITING_APPROVAL);

        return $this->success('Review queue retrieved.', [
            'awaiting' => $awaiting,
            'awaiting_total' => Order::where('status', Order::AWAITING_APPROVAL)->count(),
            'flagged' => (clone $flagged)->count(),
            'flagged_overdue' => $flagged->where('review_due_at', '<=', now())->count(),
        ]);
    }

    public function approve(Request $request, Order $order, OrderReviewService $review): JsonResponse
    {
        $review->approve($order, $request->user(), $this->note($request, false));

        return $this->success('Order approved.');
    }

    public function reject(Request $request, Order $order, OrderReviewService $review): JsonResponse
    {
        $review->reject($order, $request->user(), $this->note($request, true));

        return $this->success('Order rejected.');
    }

    public function reviewed(Request $request, Order $order, OrderReviewService $review): JsonResponse
    {
        $review->markReviewed($order, $request->user(), $this->note($request, false));

        return $this->success('Order reviewed.');
    }

    private function note(Request $request, bool $required): ?string
    {
        $message = 'Alasan penolakan wajib diisi.';
        $validated = $request->validate(
            ['note' => [$required ? 'required' : 'nullable', 'string', 'max:500']],
            ['note.required' => $message, 'note.max' => 'Catatan maksimal 500 karakter.'],
        );
        $note = trim(strip_tags($validated['note'] ?? ''));

        if ($required && $note === '') {
            throw ValidationException::withMessages(['note' => $message]);
        }

        return $note === '' ? null : $note;
    }
}
