<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\RefundService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halaman Refund admin. Izin menurut matriks D-041: refunds.view melihat, refunds.process (CS) menghubungi pelanggan, memeriksa video,
 * dan mencatat transfer, refunds.decide (Pemilik) menyetujui atau menolak.
 */
class RefundController extends Controller
{
    use ApiResponse;

    public function __construct(private RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        $page = Refund::with('order:id,order_number,customer_name')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('refund_number', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($order) => $order
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%"))))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->success('Refunds retrieved.', [
            'items' => collect($page->items())->map(fn (Refund $refund) => $this->row($refund))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                // Jumlah per status untuk penyaring cepat; tidak ikut pencarian supaya angkanya tetap.
                'counts' => Refund::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            ],
        ]);
    }

    public function show(Refund $refund): JsonResponse
    {
        $refund->load('order.shipment', 'items.orderItem', 'histories.actor:id,name', 'decidedBy:id,name');

        return $this->success('Refund retrieved.', $this->row($refund) + [
            'description' => $refund->description,
            'contact_preference' => $refund->contact_preference,
            'requester_email' => $refund->requester_email,
            'requester_phone' => $refund->requester_phone,
            'evidence_status' => $refund->evidence_status,
            'evidence_channel' => $refund->evidence_channel,
            'evidence_received_at' => $refund->evidence_received_at,
            'evidence_note' => $refund->evidence_note,
            'rejection_reason' => $refund->rejection_reason,
            'admin_note' => $refund->admin_note,
            'refund_reference' => $refund->refund_reference,
            'refunded_at' => $refund->refunded_at,
            'decided_at' => $refund->decided_at,
            'decided_by' => $refund->decidedBy?->name,
            'terms_version' => $refund->terms_version,
            'received_at' => $this->refunds->receivedAt($refund->order)?->toIso8601String(),
            'order_shipping_fee' => (int) $refund->order->shipping_fee,
            // Ongkir bisa ikut hanya untuk salah kirim atau barang kurang, dan sekali per pesanan; server memeriksa ulang saat menyetujui.
            'shipping_eligible' => $refund->status === Refund::UNDER_REVIEW
                && $refund->customer_choice === 'refund'
                && in_array($refund->reason_type, Refund::SHIPPING_REASONS, true)
                && $refund->order->shipping_fee > 0
                && ! Refund::where('order_id', $refund->order_id)->whereKeyNot($refund->id)->whereIn('status', Refund::CLAIMING)->where('shipping_amount', '>', 0)->exists(),
            'payment_status' => $refund->order->payment_status,
            'items' => $refund->items->map(fn ($line) => [
                'name' => $line->orderItem->product_name_snapshot,
                'variant' => $line->orderItem->variant_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price_snapshot,
                'line_amount' => $line->line_amount,
            ])->values(),
            'history' => $refund->histories->map(fn ($entry) => [
                'from' => $entry->from_status, 'to' => $entry->to_status, 'source' => $entry->source,
                'by' => $entry->actor?->name, 'reason' => $entry->reason, 'at' => $entry->created_at,
            ])->values(),
        ]);
    }

    public function contacted(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:300']], ['note.max' => 'Catatan maksimal 300 karakter.']);

        $this->refunds->contacted($refund, $this->clean($validated['note'] ?? null), $request->user());

        return $this->success('Refund updated.');
    }

    public function evidence(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate([
            'result' => ['required', 'in:received,insufficient'],
            'channel' => ['required', 'in:whatsapp,email'],
            'note' => ['required', 'string', 'max:200'],
        ], ['note.required' => 'Catatan pemeriksaan video wajib diisi.', 'note.max' => 'Catatan maksimal 200 karakter.']);

        $this->refunds->recordEvidence($refund, $validated['result'], $validated['channel'], $this->clean($validated['note']) ?? '', $request->user());

        return $this->success('Refund updated.');
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate([
            'approved_amount' => ['required', 'integer', 'min:1'],
            'include_shipping' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['approved_amount.required' => 'Nilai yang disetujui wajib diisi.', 'approved_amount.min' => 'Nilai disetujui minimal Rp1.', 'note.max' => 'Catatan maksimal 300 karakter.']);

        $this->refunds->approve($refund, (int) $validated['approved_amount'], (bool) ($validated['include_shipping'] ?? false), $this->clean($validated['note'] ?? null), $request->user());

        return $this->success('Refund approved.');
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Alasan penolakan wajib diisi.', 'reason.max' => 'Alasan maksimal 255 karakter.']);

        $this->refunds->reject($refund, $this->clean($validated['reason']) ?? '', $request->user());

        return $this->success('Refund rejected.');
    }

    public function choice(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(
            ['choice' => ['required', 'in:refund,resend,voucher'], 'note' => ['nullable', 'string', 'max:300']],
            ['choice.required' => 'Pilihan pelanggan wajib diisi.', 'choice.in' => 'Pilihan tidak dikenal.', 'note.max' => 'Catatan maksimal 300 karakter.'],
        );

        $this->refunds->choose($refund, $validated['choice'], $this->clean($validated['note'] ?? null), $request->user());

        return $this->success('Refund updated.');
    }

    public function cancel(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:300']], ['note.required' => 'Alasan pembatalan wajib diisi.', 'note.max' => 'Catatan maksimal 300 karakter.']);

        $this->refunds->cancel($refund, $this->clean($validated['note']) ?? '', $request->user());

        return $this->success('Refund cancelled.');
    }

    public function refunded(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['reference.required' => 'Referensi transfer wajib diisi (bukan nomor rekening).', 'reference.max' => 'Referensi maksimal 100 karakter.', 'note.max' => 'Catatan maksimal 300 karakter.']);

        $this->refunds->markRefunded($refund, $this->clean($validated['reference']) ?? '', $this->clean($validated['note'] ?? null), $request->user());

        return $this->success('Refund completed.');
    }

    /** @return array<string, mixed> */
    private function row(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'refund_number' => $refund->refund_number,
            'order_number' => $refund->order->order_number,
            'customer_name' => $refund->order->customer_name,
            'reason_type' => $refund->reason_type,
            'status' => $refund->status,
            'requested_amount' => $refund->requested_amount,
            'approved_amount' => $refund->approved_amount,
            'shipping_amount' => $refund->shipping_amount,
            'customer_choice' => $refund->customer_choice,
            'refund_method' => $refund->refund_method,
            // Total yang ditransfer: nilai barang (disetujui, atau diminta bila belum diputuskan) ditambah ongkir yang ikut.
            'total_refund' => ($refund->approved_amount ?? $refund->requested_amount) + $refund->shipping_amount,
            'due_at' => $refund->due_at,
            'is_overdue' => $refund->isOverdue(),
            'created_at' => $refund->created_at,
        ];
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim(strip_tags($text));

        return $text === '' ? null : $text;
    }
}
