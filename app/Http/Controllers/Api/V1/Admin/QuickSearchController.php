<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian cepat untuk CS: satu kolom untuk nomor pesanan, refund, atau pembatalan, nama, email, HP, dan resi.
 * Tiap jenis hanya dicari bila pengguna punya izin lihat jenis itu. Hasil hanya memuat nomor, nama, dan status:
 * email, HP, dan alamat tidak ikut, supaya hasilnya aman ditampilkan di bilah pencarian.
 */
class QuickSearchController extends Controller
{
    use ApiResponse;

    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:3', 'max:60']]);
        $terms = $this->variants(trim($validated['q']));
        $user = $request->user();

        $orders = $user->can('orders.view') ? $this->orders($terms) : [];
        $refunds = $user->can('refunds.view') ? $this->refunds($terms) : [];
        $cancellations = $user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final']) ? $this->cancellations($terms) : [];

        return $this->success('Search results.', ['groups' => array_values(array_filter([
            $orders ? ['key' => 'orders', 'label' => 'Pesanan', 'route' => 'AdminOrders', 'rows' => $orders] : null,
            $refunds ? ['key' => 'refunds', 'label' => 'Refund', 'route' => 'AdminRefunds', 'rows' => $refunds] : null,
            $cancellations ? ['key' => 'cancellations', 'label' => 'Pembatalan', 'route' => 'AdminCancellations', 'rows' => $cancellations] : null,
        ]))]);
    }

    /**
     * Nomor HP bisa ditulis 08... atau 62...; keduanya dicari.
     *
     * @return list<string>
     */
    private function variants(string $query): array
    {
        $variants = [$query];
        $digits = preg_replace('/[\s\-+]/', '', $query) ?? '';
        if (ctype_digit($digits) && strlen($digits) >= 3) {
            $variants[] = $digits;
            $variants[] = str_starts_with($digits, '62') ? '0'.substr($digits, 2) : (str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits);
        }

        return array_values(array_unique($variants));
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function orders(array $terms): array
    {
        return Order::query()
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $query->orWhere('order_number', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('customer_email', 'like', "%{$term}%")
                        ->orWhere('customer_phone', 'like', "%{$term}%")
                        ->orWhereHas('shipment', fn ($shipment) => $shipment->where('waybill_id', 'like', "%{$term}%"));
                }
            })
            ->latest('id')->limit(self::LIMIT)->get(['id', 'order_number', 'customer_name', 'status'])
            ->map(fn (Order $order) => ['id' => $order->id, 'title' => $order->order_number, 'subtitle' => $order->customer_name, 'status' => $order->status, 'search' => $order->order_number])
            ->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function refunds(array $terms): array
    {
        return Refund::with('order:id,order_number,customer_name')
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $query->orWhere('refund_number', 'like', "%{$term}%")
                        ->orWhere('requester_email', 'like', "%{$term}%")
                        ->orWhere('requester_phone', 'like', "%{$term}%")
                        ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%"));
                }
            })
            ->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (Refund $refund) => ['id' => $refund->id, 'title' => $refund->refund_number, 'subtitle' => $refund->order->customer_name.' · '.$refund->order->order_number, 'status' => $refund->status, 'search' => $refund->refund_number])
            ->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function cancellations(array $terms): array
    {
        return OrderCancellationRequest::with('order:id,order_number,customer_name')
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $query->orWhere('request_number', 'like', "%{$term}%")
                        ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%"));
                }
            })
            ->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (OrderCancellationRequest $request) => ['id' => $request->id, 'title' => $request->request_number, 'subtitle' => $request->order->customer_name.' · '.$request->order->order_number, 'status' => $request->status, 'search' => $request->request_number])
            ->all();
    }
}
