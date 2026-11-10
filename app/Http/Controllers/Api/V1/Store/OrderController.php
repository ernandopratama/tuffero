<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Services\CancellationService;
use App\Services\MidtransService;
use App\Services\Shipping\BiteshipTracker;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Akses pesanan tamu (D-050, AUTH-14). Semua kegagalan memberi jawaban sama supaya keberadaan pesanan tidak bocor.
 */
class OrderController extends Controller
{
    use ApiResponse;

    public function show(Request $request, string $ref): JsonResponse
    {
        $order = Order::with('items.variant.product.images', 'shipment', 'histories')->where('public_ref', $ref)->first();

        if (! $order || ! $this->canAccess($request, $order)) {
            return $this->error('Order not found.', [], 404);
        }

        $cancellation = $order->cancellationRequests()->latest('id')->first();

        return $this->success('Order retrieved.', [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'shipping_status' => $order->shipping_status,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'shipping_courier' => $order->shipping_courier,
            'shipping_service' => $order->shipping_service,
            'subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            'voucher_code' => $order->voucher_code,
            'voucher_label' => implode(' + ', array_filter([$order->voucher_code, $order->voucher_code_2])),
            'shipping_fee' => $order->shipping_fee,
            'total' => $order->total,
            'expires_at' => $order->expires_at,
            'created_at' => $order->created_at,
            // Pelacakan paket (kartu Lacak Paket): nomor resi diisi admin saat paket diserahkan ke kurir; waktu tiap tahap dari riwayat status pesanan kita.
            'shipment' => $order->shipment?->only(['waybill_id', 'status', 'shipped_at', 'delivered_at', 'updated_at']),
            'timeline' => $order->histories
                ->where('status_type', 'order')
                ->whereIn('to_status', [Order::PAID, Order::PROCESSING, Order::SHIPPED, Order::COMPLETED])
                ->sortBy('id')
                ->unique('to_status')
                ->mapWithKeys(fn ($history) => [$history->to_status => $history->created_at]),
            'can_cancel' => $order->canBeCancelled() && ! $cancellation?->isOpen(),
            'cancellation' => $cancellation?->only(['request_number', 'status', 'rejection_reason']),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'sku' => $item->sku_snapshot,
                'product_name' => $item->product_name_snapshot,
                'variant_name' => $item->variant_name_snapshot,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'photo' => ($path = $item->variant?->product?->images->first()?->path) ? '/storage/'.$path : null,
            ]),
        ]);
    }

    /**
     * Periksa status bayar langsung ke Midtrans. Hasilnya hanya mengubah pesanan bila Midtrans sendiri menyatakan lunas (BR-11); respons hanya status terkini.
     */
    public function syncPayment(Request $request, string $ref, MidtransService $midtrans): JsonResponse
    {
        $order = Order::where('public_ref', $ref)->first();

        if (! $order || ! $this->canAccess($request, $order)) {
            return $this->error('Order not found.', [], 404);
        }

        $midtrans->syncStatus($order);

        return $this->success('Payment status checked.', ['status' => $order->fresh()->status]);
    }

    /**
     * Riwayat paket per hub/kota dari kurir lewat Biteship. Tidak tersedia (available: false) bukan galat: kartu pelacakan tetap tampil dari data pesanan.
     */
    public function tracking(Request $request, string $ref, BiteshipTracker $tracker): JsonResponse
    {
        $order = Order::with('shipment')->where('public_ref', $ref)->first();

        if (! $order || ! $this->canAccess($request, $order)) {
            return $this->error('Order not found.', [], 404);
        }

        $events = $order->shipment ? $tracker->sync($order->shipment) : null;

        return $this->success('Tracking retrieved.', ['available' => $events !== null, 'events' => $events ?? []]);
    }

    public function requestCancellation(Request $request, string $ref, CancellationService $cancellations): JsonResponse
    {
        $order = Order::where('public_ref', $ref)->first();

        if (! $order || ! $this->canAccess($request, $order)) {
            return $this->error('Order not found.', [], 404);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'channel' => ['required', Rule::in(['whatsapp', 'email'])],
        ], ['reason.required' => 'reason_required', 'reason.max' => 'reason_too_long']);

        $cancellation = $cancellations->submit($order, $validated);

        return $this->success('Cancellation requested.', [
            'request_number' => $cancellation->request_number,
            'contact_url' => $this->contactUrl($cancellation->request_number, $validated['channel']),
        ], 201);
    }

    public function payment(Request $request, string $ref, MidtransService $midtrans): JsonResponse
    {
        $order = Order::where('public_ref', $ref)->first();

        if (! $order || ! $this->canAccess($request, $order)) {
            return $this->error('Order not found.', [], 404);
        }

        if (! $midtrans->enabled()) {
            return $this->error('Payment is not available.', [], 503);
        }

        try {
            $token = $midtrans->snapToken($order);
        } catch (RuntimeException) {
            return $this->error('Payment is not available.', [], 503);
        }

        return $this->success('Payment token created.', ['snap_token' => $token]);
    }

    /**
     * Tautan hanya membawa nomor pengajuan, tanpa data pribadi (D-043). Kontak dari Pengaturan.
     */
    private function contactUrl(string $requestNumber, string $channel): ?string
    {
        $value = Setting::where('key', $channel === 'whatsapp' ? 'contact.whatsapp_number' : 'contact.admin_email')->value('value');

        if (! $value) {
            return null;
        }

        return $channel === 'whatsapp'
            ? 'https://wa.me/'.preg_replace('/\D/', '', $value).'?text='.rawurlencode("Pengajuan pembatalan {$requestNumber}")
            : 'mailto:'.$value.'?subject='.rawurlencode("Pengajuan pembatalan {$requestNumber}");
    }

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:20'],
            'contact' => ['required', 'string', 'max:190'],
        ]);

        $order = Order::findForContact($validated['order_number'], $validated['contact']);

        if (! $order) {
            return $this->error('Order not found.', [], 404);
        }

        $request->session()->put("order_access.{$order->public_ref}", true);

        return $this->success('Order found.', ['public_ref' => $order->public_ref]);
    }

    private function canAccess(Request $request, Order $order): bool
    {
        $token = $request->header('X-Order-Token');

        return ($order->user_id !== null && $order->user_id === $request->user()?->id)
            || $request->session()->get("order_access.{$order->public_ref}") === true
            || (is_string($token) && hash_equals($order->access_token_hash, hash('sha256', $token)));
    }
}
