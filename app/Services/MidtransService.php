<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentEvent;
use App\Notifications\StaffEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Midtrans Snap (D-015, BR-11). Server key hanya di server; status lunas hanya dari notifikasi yang lolos verifikasi.
 * Metode bayar dibatasi ke QRIS, virtual account, dan kartu (config services.midtrans.enabled_payments); yang benar-benar muncul tetap yang aktif di dashboard Midtrans.
 */
class MidtransService
{
    /** Metode bawaan jendela bayar (keputusan pemilik 2026-11-07): QRIS, virtual account, dan kartu. */
    public const DEFAULT_PAYMENT_METHODS = ['gopay', 'shopeepay', 'other_qris', 'bca_va', 'bni_va', 'bri_va', 'permata_va', 'echannel', 'cimb_va', 'other_va', 'credit_card'];

    public function __construct(private OrderStatusService $status, private StaffNotifier $notifier) {}

    public function enabled(): bool
    {
        return filled(config('services.midtrans.server_key')) && filled(config('services.midtrans.client_key'));
    }

    public function snapJsUrl(): string
    {
        return config('services.midtrans.is_production')
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }

    /**
     * Token Snap untuk pesanan yang masih menunggu bayar. Total diambil dari database, tidak dari browser.
     */
    public function snapToken(Order $order): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Midtrans is not configured.');
        }

        // Kunci baris: dua klik bersamaan tidak boleh membuat dua transaksi dengan order_id yang sama.
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $minutes = (int) floor(now()->diffInSeconds($locked->expires_at, false) / 60);

            if ($locked->status !== Order::PENDING_PAYMENT || $minutes < 1) {
                throw ValidationException::withMessages(['order' => 'not_payable']);
            }

            if ($locked->snap_token) {
                return $locked->snap_token;
            }

            $response = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
                ->acceptJson()
                ->timeout(15)
                ->post($this->snapApiUrl(), [
                    'transaction_details' => ['order_id' => $locked->order_number, 'gross_amount' => $locked->total],
                    // Rincian item sengaja tidak dikirim: diskon voucher dan ongkir harus dijumlahkan persis sama dengan gross_amount.
                    'customer_details' => [
                        'first_name' => $locked->customer_name,
                        'email' => $locked->customer_email,
                        'phone' => $locked->customer_phone,
                    ],
                    // Batas bayar Midtrans sama dengan batas pesanan, supaya pembayaran tidak bisa selesai setelah stok dilepas.
                    'expiry' => ['start_time' => now()->format('Y-m-d H:i:s O'), 'unit' => 'minute', 'duration' => $minutes],
                    ...(config('services.midtrans.enabled_payments') ? ['enabled_payments' => config('services.midtrans.enabled_payments')] : []),
                ]);

            $token = $response->json('token');

            if (! $response->successful() || ! is_string($token)) {
                Log::warning('Midtrans Snap token request failed', ['order' => $locked->order_number, 'status' => $response->status()]);

                throw new RuntimeException('Midtrans Snap token request failed.');
            }

            $locked->forceFill(['snap_token' => $token])->save();

            return $token;
        });
    }

    /**
     * Notifikasi HTTP Midtrans. Mengembalikan false bila tanda tangan atau isinya tidak cocok.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleNotification(array $payload): bool
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_id', 'transaction_status'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '') {
                return false;
            }
        }

        $expected = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('services.midtrans.server_key'));

        if (! $this->enabled() || ! hash_equals($expected, $payload['signature_key'])) {
            Log::warning('Midtrans notification with invalid signature', ['order_id' => $payload['order_id']]);

            return false;
        }

        $order = Order::where('order_number', $payload['order_id'])->first();
        $amount = $payload['gross_amount'];

        if (! $order || ! is_numeric($amount) || (float) $amount !== (float) $order->total) {
            Log::warning('Midtrans notification does not match an order', ['order_id' => $payload['order_id']]);

            return false;
        }

        try {
            DB::transaction(function () use ($order, $payload) {
                PaymentEvent::create([
                    'order_id' => $order->id,
                    'transaction_id' => $payload['transaction_id'],
                    'transaction_status' => $payload['transaction_status'],
                    'fraud_status' => $payload['fraud_status'] ?? null,
                    'payment_type' => $payload['payment_type'] ?? null,
                    'gross_amount' => $order->total,
                    'payload' => collect($payload)->except('signature_key')->all(),
                ]);

                if ($this->isPaid($payload)) {
                    $this->settle($order);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Notifikasi ulang untuk status yang sama: sudah diproses.
        }

        return true;
    }

    /**
     * Status lain (pending, deny, cancel, expire, refund) hanya dicatat. Kedaluwarsa diurus OrderExpiryService,
     * refund dilakukan manual (BUSINESS_RULES), dan pelanggan masih bisa mencoba metode lain setelah deny.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isPaid(array $payload): bool
    {
        return $payload['transaction_status'] === 'settlement'
            || ($payload['transaction_status'] === 'capture' && ($payload['fraud_status'] ?? 'accept') === 'accept');
    }

    private function settle(Order $order): void
    {
        $result = $this->status->markPaid($order);

        // Uang masuk tetapi pesanan sudah ditutup: status tidak diubah, admin mengembalikan dana secara manual.
        if (in_array($result->status, [Order::EXPIRED, Order::CANCELLED], true)) {
            $this->notifier->toRoles(['store-admin'], new StaffEvent('payment_late', 'order', $result->order_number, "Pembayaran masuk untuk pesanan {$result->order_number} yang sudah ditutup. Perlu refund manual.", $result->customer_name));
        }
    }

    /**
     * Tanya status transaksi langsung ke Midtrans (API Status) dan proses hasilnya lewat jalur yang sama dengan notifikasi:
     * tanda tangan, ID pesanan, dan jumlah diverifikasi, dan hanya status settlement/capture yang melunasi (BR-11).
     * Dipakai tombol "Saya sudah membayar" dan pemeriksaan berkala, untuk notifikasi yang terlambat atau tak terjangkau (mis. server lokal).
     * Mengembalikan false bila tidak ada transaksi di Midtrans, galat jaringan, atau cooldown 5 detik per pesanan masih berlaku.
     */
    public function syncStatus(Order $order): bool
    {
        if (! $this->enabled() || $order->status !== Order::PENDING_PAYMENT || ! $order->snap_token) {
            return false;
        }

        if (! Cache::add("midtrans:sync:{$order->id}", 1, 5)) {
            return false;
        }

        try {
            $response = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->get($this->statusApiUrl($order->order_number));
        } catch (Throwable $e) {
            Log::warning('Midtrans status request failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);

            return false;
        }

        // 404 berarti pembeli belum memilih metode bayar, jadi belum ada transaksi: bukan galat.
        if (! $response->successful() || ! is_array($response->json())) {
            return false;
        }

        return $this->handleNotification($response->json());
    }

    private function statusApiUrl(string $orderNumber): string
    {
        return (config('services.midtrans.is_production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com').'/v2/'.rawurlencode($orderNumber).'/status';
    }

    private function snapApiUrl(): string
    {
        return config('services.midtrans.is_production')
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }
}
