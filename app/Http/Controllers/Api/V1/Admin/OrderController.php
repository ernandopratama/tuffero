<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Warehouse;
use App\Services\CancellationService;
use App\Services\OrderStatusService;
use App\Traits\ApiResponse;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use ApiResponse;

    private const WEBSITE = 'https://tuffero.com';

    private const COURIERS = ['jnt', 'sicepat'];

    /** Daftar ambil dan cetak resi massal dibatasi supaya satu permintaan tidak membengkak. */
    public const BULK_LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $payment = $request->string('payment_status')->trim()->toString();

        $page = Order::with('shipment:id,order_id,provider,status,waybill_id')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($payment !== '', fn ($query) => $query->where('payment_status', $payment))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_email', 'like', "%{$search}%")
                ->orWhere('customer_phone', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paged('Orders retrieved.', $page, fn (Order $order) => [
            ...$order->only(['id', 'order_number', 'customer_name', 'status', 'payment_status', 'shipping_status', 'total', 'created_at']),
            // Status paket dari Biteship (webhook); null bila belum ada pengiriman atau resi manual.
            'carrier_status' => $order->shipment?->provider === 'biteship' ? $order->shipment->status : null,
            // Tombol Cetak Resi di daftar: hanya tampil bila resi sudah ada.
            'waybill_id' => $order->shipment?->waybill_id,
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
            'shipment' => $order->shipment?->only(['provider', 'status', 'courier_code', 'service_code', 'waybill_id', 'note', 'shipped_at', 'delivered_at', 'updated_at']),
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
    public function fulfillment(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $courier = $request->string('courier')->trim()->toString();
        $waybill = $request->string('waybill')->trim()->toString();
        $newestFirst = $request->query('sort') === 'newest';

        $page = Order::with(['items', 'shipment'])
            ->whereIn('status', [Order::PAID, Order::PROCESSING])
            ->when(in_array($status, [Order::PAID, Order::PROCESSING], true), fn ($query) => $query->where('status', $status))
            ->when(in_array($courier, self::COURIERS, true), fn ($query) => $query->where('shipping_courier', $courier))
            ->when($waybill === 'with', fn ($query) => $query->whereHas('shipment', fn ($shipment) => $shipment->whereNotNull('waybill_id')))
            ->when($waybill === 'without', fn ($query) => $query->whereDoesntHave('shipment', fn ($shipment) => $shipment->whereNotNull('waybill_id')))
            ->when($search !== '', fn ($query) => $query->where('order_number', 'like', "%{$search}%"))
            ->orderBy('paid_at', $newestFirst ? 'desc' : 'asc')
            ->orderBy('id', $newestFirst ? 'desc' : 'asc')
            ->paginate($this->perPage($request));

        return $this->paged('Fulfillment retrieved.', $page, fn (Order $order) => [
            ...$order->only(['id', 'order_number', 'status', 'shipping_status', 'shipping_address', 'shipping_courier', 'shipping_service', 'paid_at']),
            'waybill_id' => $order->shipment?->waybill_id,
            'shipment' => $order->shipment?->only(['provider', 'status', 'waybill_id', 'note']),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'sku' => $item->sku_snapshot,
                'product_name' => $item->product_name_snapshot,
                'variant_name' => $item->variant_name_snapshot,
                'quantity' => $item->quantity,
            ]),
        ]);
    }

    /**
     * Rekap barang yang harus diambil dari rak untuk beberapa pesanan sekaligus. Hanya pesanan terbayar atau diproses;
     * tanpa harga, pembayaran, atau data pembeli (gudang tidak membutuhkannya untuk mengambil barang).
     */
    public function pickList(Request $request): JsonResponse
    {
        // ?ids=1,2,3; bentuk lain (misalnya ids[]=1) dianggap kosong, bukan galat server.
        $raw = $request->query('ids');
        $ids = collect(is_string($raw) ? explode(',', $raw) : [])
            ->map(fn (string $id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();

        if ($ids->isEmpty() || $ids->count() > self::BULK_LIMIT) {
            throw ValidationException::withMessages(['ids' => 'Pilih 1 sampai '.self::BULK_LIMIT.' pesanan.']);
        }

        $orders = Order::with('items')->whereIn('id', $ids)->whereIn('status', [Order::PAID, Order::PROCESSING])->orderBy('paid_at')->orderBy('id')->get();

        $lines = $orders->flatMap(fn (Order $order) => $order->items)
            ->groupBy('sku_snapshot')
            ->map(fn ($items, $sku) => [
                'sku' => $sku,
                'product_name' => $items->first()->product_name_snapshot,
                'variant_name' => $items->first()->variant_name_snapshot,
                'quantity' => (int) $items->sum('quantity'),
                'orders' => $items->pluck('order_id')->unique()->count(),
            ])
            ->sortBy([['product_name', 'asc'], ['sku', 'asc']])
            ->values();

        return $this->success('Pick list retrieved.', [
            'generated_at' => now()->toIso8601String(),
            'requested' => $ids->count(),
            'skipped' => $ids->count() - $orders->count(),
            'total_quantity' => (int) $lines->sum('quantity'),
            'lines' => $lines,
            'orders' => $orders->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'courier' => strtoupper(trim($order->shipping_courier.' '.$order->shipping_service)),
                'paid_at' => $order->paid_at,
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'sku' => $item->sku_snapshot,
                    'name' => $item->product_name_snapshot,
                    'variant' => $item->variant_name_snapshot,
                    'quantity' => $item->quantity,
                ]),
            ])->values(),
        ]);
    }

    public function shipments(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $shipping = $request->string('shipping_status')->trim()->toString();

        $page = Order::with('shipment')
            ->whereIn('status', [Order::PROCESSING, Order::SHIPPED, Order::COMPLETED])
            ->when($shipping !== '', fn ($query) => $query->where('shipping_status', $shipping))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paged('Shipments retrieved.', $page, fn (Order $order) => [
            ...$order->only(['id', 'order_number', 'status', 'shipping_status', 'shipping_courier', 'shipping_service']),
            'recipient' => $order->shipping_address['recipient'] ?? null,
            'city' => $order->shipping_address['city'] ?? null,
            'waybill_id' => $order->shipment?->waybill_id,
            // OrderActions butuh `shipment` untuk tahu resi sudah dibuat; tanpanya tombol Proses Pesanan muncul lagi.
            'shipment' => $order->shipment?->only(['provider', 'status', 'waybill_id', 'note']),
            'shipped_at' => $order->shipment?->shipped_at,
            'delivered_at' => $order->shipment?->delivered_at,
        ]);
    }

    /**
     * Status pembayaran hanya untuk dilihat (D-041). Tidak ada jalur ubah manual.
     */
    public function payments(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $payment = $request->string('payment_status')->trim()->toString();

        $page = Order::query()
            ->when($payment !== '', fn ($query) => $query->where('payment_status', $payment))
            ->when($search !== '', fn ($query) => $query->where('order_number', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        // Dana masuk untuk pesanan yang sudah ditutup: ditandai supaya Admin Toko melihat tombol "dana sudah dikembalikan".
        $needsRefund = Order::latePaymentClosed()->whereIn('id', collect($page->items())->pluck('id'))->pluck('id')->all();

        return $this->paged('Payments retrieved.', $page, fn (Order $order) => $order->only([
            'id', 'order_number', 'payment_status', 'total', 'paid_at', 'expires_at', 'created_at',
        ]) + ['needs_refund' => in_array($order->id, $needsRefund, true)]);
    }

    /** Dana masuk untuk pesanan yang sudah ditutup sudah dikembalikan manual. Satu Admin Toko cukup; catatan wajib. */
    public function markLateRefunded(Request $request, Order $order, CancellationService $cancellations): JsonResponse
    {
        $validated = $request->validate(
            ['note' => ['required', 'string', 'max:500']],
            ['note.required' => 'Catatan wajib diisi, misalnya referensi transfer.', 'note.max' => 'Catatan maksimal 500 karakter.'],
        );

        $note = trim(strip_tags($validated['note']));
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi, misalnya referensi transfer.']);
        }

        $cancellations->markLatePaymentRefunded($order, $note, $request->user());

        return $this->success('Late payment refund recorded.');
    }

    public function startProcessing(Request $request, Order $order, OrderStatusService $status): JsonResponse
    {
        $status->startProcessing($order, $request->user());

        return $this->success('Order updated.');
    }

    public function ship(Request $request, Order $order, OrderStatusService $status): JsonResponse
    {
        $validated = $request->validate(
            ['waybill_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/']],
            ['waybill_id.regex' => 'Nomor resi hanya boleh huruf, angka, dan tanda hubung.', 'waybill_id.max' => 'Nomor resi terlalu panjang.'],
        );

        // Resi kosong = buat pengiriman di Biteship (resi otomatis, D-082). Resi diisi = cadangan manual atau koreksi.
        filled($validated['waybill_id'] ?? null)
            ? $status->ship($order, $validated['waybill_id'], $request->user())
            : $status->shipViaBiteship($order, $request->user());

        return $this->success('Order updated.');
    }

    /** "Proses Pesanan": resi Biteship (default) atau resi manual dengan catatan. */
    public function arrange(Request $request, Order $order, OrderStatusService $status): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:biteship,manual'],
            'waybill_id' => ['required_if:method,manual', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['waybill_id.required_if' => 'Nomor resi wajib diisi untuk resi manual.', 'waybill_id.regex' => 'Nomor resi hanya boleh huruf, angka, dan tanda hubung.', 'waybill_id.max' => 'Nomor resi terlalu panjang.', 'note.max' => 'Catatan maksimal 300 karakter.']);

        $status->arrange($order, $request->user(), $validated['method'], $validated['waybill_id'] ?? null, isset($validated['note']) ? trim(strip_tags($validated['note'])) ?: null : null);

        return $this->success('Order processed.');
    }

    public function markShipped(Request $request, Order $order, OrderStatusService $status): JsonResponse
    {
        $status->markShipped($order, $request->user());

        return $this->success('Order updated.');
    }

    /**
     * Data label pengiriman. Tanpa harga, pembayaran, dan email (D-041): label dibaca staf gudang dan kurir.
     */
    public function label(Order $order): JsonResponse
    {
        $order->load(['items', 'shipment']);

        if (! $order->shipment?->waybill_id) {
            return $this->error('Belum ada nomor resi untuk dicetak.', ['status' => ['Belum ada nomor resi untuk dicetak.']], 422);
        }

        $warehouse = Warehouse::find($order->warehouse_id);
        $address = $order->shipping_address;

        return $this->success('Label retrieved.', [
            'order_number' => $order->order_number,
            'provider' => $order->shipment->provider,
            'waybill_id' => $order->shipment->waybill_id,
            'note' => $order->shipment->note,
            'courier' => strtoupper($order->shipping_courier.' '.$order->shipping_service),
            'sender' => ['name' => 'Tuffero Patisserie', 'address' => $warehouse?->address, 'postal_code' => $warehouse?->postal_code, 'phone' => $warehouse?->phone],
            'recipient' => [
                'name' => $address['recipient'] ?? $order->customer_name,
                'phone' => $address['phone'] ?? $order->customer_phone,
                'address' => implode(', ', array_filter([$address['street'] ?? null, $address['district'] ?? null, $address['city'] ?? null, $address['province'] ?? null])).' '.($address['postal_code'] ?? ''),
            ],
            'weight_gram' => (int) $order->items->sum(fn (OrderItem $item) => $item->weight_gram_snapshot * $item->quantity),
            'website' => self::WEBSITE,
            'qr' => $this->qrRows(self::WEBSITE),
            'items' => $order->items->map(fn (OrderItem $item) => ['name' => $item->product_name_snapshot, 'variant' => $item->variant_name_snapshot, 'sku' => $item->sku_snapshot, 'quantity' => $item->quantity]),
        ]);
    }

    /** QR asli (bukan gambar statis) sebagai baris "1" dan "0"; halaman label menggambarnya sebagai kotak hitam putih. */
    private function qrRows(string $content): array
    {
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();

        return array_map(
            fn (int $y) => implode('', array_map(fn (int $x) => $matrix->get($x, $y) ? '1' : '0', range(0, $matrix->getWidth() - 1))),
            range(0, $matrix->getHeight() - 1),
        );
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
