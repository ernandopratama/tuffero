<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Models\ChatFaq;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Chat\ChatAssistant;
use App\Services\Chat\ChatModelException;
use App\Services\Shipping\ShippingProvider;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Jawaban untuk chat toko (D-067). Cek pesanan dan ongkir hanya mengembalikan data yang sudah dibatasi,
 * jadi chat tidak pernah membuka alamat, nama, email, atau HP pelanggan. Pertanyaan bebas dijawab asisten AI tanpa akses data (D-081).
 */
class ChatController extends Controller
{
    use ApiResponse;

    /**
     * FAQ aktif untuk chat. Isinya publik, jadi tanpa data pelanggan. Pencocokan dengan teks pengguna dilakukan
     * di browser, sehingga isi chat tidak dikirim ke server.
     */
    public function faqs(Request $request): JsonResponse
    {
        $english = $request->query('lang') === 'en';

        $items = ChatFaq::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn (ChatFaq $faq) => [
            'id' => $faq->id,
            'title' => ($english ? $faq->title_en : null) ?: $faq->title,
            'suggested' => $faq->is_suggested,
            'keywords' => $faq->keywordList(),
            'answer' => ($english ? $faq->answer_en : null) ?: $faq->answer_id,
        ]);

        // `ai` memberi tahu widget apakah pertanyaan bebas boleh diteruskan ke asisten AI (dan pemberitahuannya ditampilkan).
        return $this->success('FAQs retrieved.', ['items' => $items, 'ai' => ChatAssistant::enabled()]);
    }

    /**
     * Pertanyaan bebas yang tidak cocok dengan menu atau FAQ dijawab asisten AI (D-081). Riwayat dikirim browser;
     * tidak ada yang disimpan di server dan isi chat tidak ditulis ke log (D-063, D-080).
     */
    public function ask(Request $request, ChatAssistant $assistant): JsonResponse
    {
        if (! ChatAssistant::enabled()) {
            return $this->error('AI chat unavailable.', [], 503);
        }

        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.ChatAssistant::MAX_MESSAGES],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.text' => ['required', 'string', 'max:1200'],
        ]);

        // Pesan terakhir harus pertanyaan pelanggan, dengan batas panjang yang sama seperti kolom chat.
        $last = end($validated['messages']);
        if ($last['role'] !== 'user' || mb_strlen($last['text']) > 500) {
            throw ValidationException::withMessages(['messages' => 'invalid']);
        }

        try {
            $reply = $assistant->answer(array_values($validated['messages']));
        } catch (ChatModelException $exception) {
            Log::warning('Chat AI gagal menjawab.', ['reason' => $exception->getMessage()]);

            return $this->error('AI chat failed.', [], 502);
        }

        return $this->success('Reply generated.', ['reply' => $reply]);
    }

    /** Aturan akses sama dengan Cek Pesanan; semua kegagalan memberi jawaban yang sama. */
    public function orderStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:20'],
            'contact' => ['required', 'string', 'max:190'],
        ]);

        $order = Order::findForContact($validated['order_number'], $validated['contact'])?->load('shipment');

        if (! $order) {
            return $this->error('Order not found.', [], 404);
        }

        return $this->success('Order status retrieved.', [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'courier' => $order->shipping_courier,
            'service' => $order->shipping_service,
            'waybill' => $order->shipment?->waybill_id,
            'expires_at' => $order->status === Order::PENDING_PAYMENT ? $order->expires_at : null,
        ]);
    }

    /** Ongkir untuk satu varian: berat dihitung server dari data varian, bukan dari kiriman browser. */
    public function shippingRates(Request $request, ShippingProvider $shipping): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
        ], ['postal_code.required' => 'postal_invalid', 'postal_code.regex' => 'postal_invalid']);

        $variant = ProductVariant::where('sku', $validated['sku'])
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_published', true))
            ->first();

        if (! $variant) {
            throw ValidationException::withMessages(['sku' => 'unavailable']);
        }

        $weight = $variant->weight_gram * min($validated['quantity'], $variant->max_order_quantity);

        return $this->success('Rates retrieved.', [
            'rates' => $shipping->rates(['postal_code' => $validated['postal_code']], $weight),
        ]);
    }
}
