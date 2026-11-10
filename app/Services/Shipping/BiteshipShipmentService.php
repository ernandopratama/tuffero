<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Order pengiriman di Biteship (D-082): dibuat saat admin menekan Kirim, kurir menjemput di gudang. Kunci API hanya di server (BR-20).
 * Webhook order.status memperbarui status paket; keaslian dibuktikan rahasia bersama (Biteship tidak mendokumentasikan tanda tangan).
 */
class BiteshipShipmentService
{
    private const COURIERS = ['jnt', 'sicepat'];

    public function enabled(): bool
    {
        return filled(config('services.biteship.key')) && filled(config('services.biteship.origin_postal_code'));
    }

    public function supports(Order $order): bool
    {
        return $this->enabled() && in_array($order->shipping_courier, self::COURIERS, true);
    }

    /**
     * Membuat order di Biteship. Dipanggil di dalam transaksi + kunci baris pesanan oleh OrderStatusService::shipViaBiteship,
     * jadi satu pesanan tidak bisa membuat dua order. reference_id = nomor pesanan (unik di Biteship) sebagai pengaman kedua.
     *
     * @return array{provider_order_id: string, tracking_id: ?string, waybill_id: ?string, status: string}
     */
    public function createOrder(Order $order): array
    {
        $warehouse = Warehouse::findOrFail($order->warehouse_id);
        $address = $order->shipping_address;

        if (blank($warehouse->address) || blank($warehouse->postal_code) || blank($warehouse->phone)) {
            throw ValidationException::withMessages(['status' => 'Alamat, kode pos, atau telepon gudang belum diisi.']);
        }

        try {
            $response = Http::baseUrl((string) config('services.biteship.base_url'))
                ->withHeaders(['Authorization' => (string) config('services.biteship.key')])
                ->acceptJson()
                ->timeout(20)
                ->connectTimeout(5)
                ->post('/v1/orders', [
                    'origin_contact_name' => $warehouse->name,
                    'origin_contact_phone' => $warehouse->phone,
                    'origin_address' => $warehouse->address,
                    'origin_postal_code' => (int) $warehouse->postal_code,
                    'origin_collection_method' => 'pickup',
                    'destination_contact_name' => (string) ($address['recipient'] ?? $order->customer_name),
                    'destination_contact_phone' => $this->localPhone((string) ($address['phone'] ?? $order->customer_phone)),
                    'destination_contact_email' => $order->customer_email,
                    'destination_address' => implode(', ', array_filter([$address['street'] ?? null, $address['district'] ?? null, $address['city'] ?? null, $address['province'] ?? null])),
                    'destination_postal_code' => (int) ($address['postal_code'] ?? 0),
                    'courier_company' => $order->shipping_courier,
                    'courier_type' => $order->shipping_service,
                    'delivery_type' => 'now',
                    'reference_id' => $order->order_number,
                    'items' => $order->items->map(fn ($item) => [
                        'name' => mb_substr(trim($item->product_name_snapshot.' '.$item->variant_name_snapshot), 0, 100),
                        'value' => $item->unit_price,
                        'quantity' => $item->quantity,
                        'weight' => max(1, (int) $item->weight_gram_snapshot),
                    ])->values()->all(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Biteship order request failed.', ['order' => $order->order_number, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['status' => 'Biteship tidak dapat dihubungi. Coba lagi sebentar, atau isi nomor resi manual.']);
        }

        if (! $response->successful() || ! $response->json('success') || blank($response->json('id'))) {
            Log::warning('Biteship order rejected.', ['order' => $order->order_number, 'status' => $response->status(), 'code' => $response->json('code'), 'error' => $response->json('error')]);

            throw ValidationException::withMessages(['status' => match ((int) $response->json('code')) {
                40002060 => 'Order ini sudah pernah dibuat di Biteship. Periksa dashboard Biteship, lalu isi nomor resi manual.',
                default => 'Biteship menolak pengiriman: '.mb_substr((string) $response->json('error', 'alasan tidak diketahui'), 0, 200).'. Perbaiki, atau isi nomor resi manual.',
            }]);
        }

        return [
            'provider_order_id' => (string) $response->json('id'),
            'tracking_id' => $response->json('courier.tracking_id'),
            'waybill_id' => $response->json('courier.waybill_id'),
            'status' => (string) $response->json('status', 'confirmed'),
        ];
    }

    /**
     * Webhook Biteship. Mengembalikan false hanya untuk rahasia salah (403). Event tak dikenal atau paket tak ada: true (200) supaya Biteship tidak mengulang.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload, ?string $secret, OrderStatusService $orders): bool
    {
        // Biteship menguji URL saat webhook dibuat dengan body kosong dan wajib dijawab 200. Tanpa event tidak ada data yang diproses.
        if (! isset($payload['event'])) {
            return true;
        }

        $expected = (string) config('services.biteship.webhook_secret');

        if ($expected === '' || ! is_string($secret) || ! hash_equals($expected, $secret)) {
            Log::warning('Biteship webhook rejected.');

            return false;
        }

        $event = $payload['event'];
        $biteshipId = $payload['order_id'] ?? null;

        if (! in_array($event, ['order.status', 'order.waybill_id'], true) || ! is_string($biteshipId) || $biteshipId === '') {
            return true;
        }

        $shipment = Shipment::where('provider', 'biteship')->where('provider_order_id', $biteshipId)->first();

        if (! $shipment) {
            return true;
        }

        $waybill = $payload['courier_waybill_id'] ?? null;

        $orders->applyCarrierUpdate(
            $shipment,
            // Dokumentasi menulis status camelCase (inTransit); disimpan snake_case supaya konsisten.
            is_string($payload['status'] ?? null) ? mb_substr(Str::snake($payload['status']), 0, 30) : null,
            is_string($waybill) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $waybill) ? $waybill : null,
        );

        // Riwayat berikutnya harus segar dari Biteship, bukan dari cache 15 menit.
        Cache::forget("biteship:tracking:{$shipment->courier_code}:{$shipment->waybill_id}");

        return true;
    }

    private function localPhone(string $phone): string
    {
        return preg_replace('/^\+62/', '0', $phone);
    }
}
