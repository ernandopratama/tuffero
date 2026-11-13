<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Models\Setting;
use App\Services\RefundService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengajuan refund dan retur oleh pelanggan, tanpa akun: kepemilikan dibuktikan nomor pesanan dan email atau HP (D-031).
 * Harga, jumlah, dan batas waktu selalu dihitung server dari database.
 */
class RefundController extends Controller
{
    use ApiResponse;

    public function lookup(Request $request, RefundService $refunds): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:20'],
            'contact' => ['required', 'string', 'max:190'],
        ]);

        return $this->success('Order found.', $refunds->lookup($validated['order_number'], $validated['contact']));
    }

    public function store(Request $request, RefundService $refunds): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');

        if (strlen($key) < 8 || strlen($key) > 64) {
            return $this->error('Idempotency-Key header is required.', [], 422);
        }

        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:20'],
            'contact' => ['required', 'string', 'max:190'],
            'reason_type' => ['required', Rule::in(Refund::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
            'contact_preference' => ['required', Rule::in(['whatsapp', 'email'])],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'terms' => ['accepted'],
        ], [
            'description.required' => 'description_required', 'description.min' => 'description_short', 'description.max' => 'description_long',
            'items.required' => 'items_invalid', 'items.min' => 'items_invalid', 'items.*.quantity.min' => 'items_invalid',
            'terms.accepted' => 'terms_required', 'reason_type.required' => 'reason_required',
        ]);

        $validated['description'] = trim(strip_tags($validated['description']));

        ['refund' => $refund, 'created' => $created] = $refunds->submit($validated, $key, $request->ip(), $request->userAgent());

        return $this->success('Refund requested.', [
            'refund_number' => $refund->refund_number,
            'public_ref' => $refund->public_ref,
            'reason_type' => $refund->reason_type,
            'requested_amount' => $refund->requested_amount,
            'contact_url' => $this->contactUrl($refund),
        ], $created ? 201 : 200);
    }

    /** Tautan WhatsApp atau email admin yang sudah memuat nomor refund, untuk mengirim video unboxing. */
    private function contactUrl(Refund $refund): ?string
    {
        $email = $refund->contact_preference === 'email';
        $value = Setting::where('key', $email ? 'contact.admin_email' : 'contact.whatsapp_number')->value('value');

        if (! $value) {
            return null;
        }

        $text = "Pengajuan refund {$refund->refund_number}. Video unboxing terlampir.";

        return $email
            ? 'mailto:'.$value.'?subject='.rawurlencode($text)
            : 'https://wa.me/'.preg_replace('/\D/', '', $value).'?text='.rawurlencode($text);
    }
}
