<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tarif ongkir dari Biteship (D-020): J&T dan SiCepat sejauh tersedia untuk akun Tuffero.
 * Kunci API hanya di server (BR-20). Gagal apa pun dianggap ongkir tidak tersedia, bukan tarif nol.
 */
class BiteshipShippingProvider implements ShippingProvider
{
    private const COURIERS = 'jnt,sicepat';

    public function rates(array $address, int $weightGram): array
    {
        try {
            $response = Http::baseUrl((string) config('services.biteship.base_url'))
                ->withHeaders(['Authorization' => (string) config('services.biteship.key')])
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->post('/v1/rates/couriers', [
                    'origin_postal_code' => (int) config('services.biteship.origin_postal_code'),
                    'destination_postal_code' => (int) $address['postal_code'],
                    'couriers' => self::COURIERS,
                    // shortcut: satu paket gabungan tanpa nilai barang, ganti dengan item asli saat order Biteship dibuat.
                    'items' => [['name' => 'Paket Tuffero', 'value' => 0, 'weight' => max(1, $weightGram), 'quantity' => 1]],
                ]);
        } catch (Throwable $e) {
            Log::warning('Biteship rates request failed.', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages(['shipping' => 'shipping_unavailable']);
        }

        if (! $response->successful() || ! $response->json('success')) {
            Log::warning('Biteship rates rejected.', ['status' => $response->status(), 'error' => $response->json('error')]);

            throw ValidationException::withMessages(['shipping' => 'shipping_unavailable']);
        }

        return collect($response->json('pricing', []))
            ->filter(fn ($rate) => is_array($rate) && isset($rate['courier_code'], $rate['courier_service_code'], $rate['price']))
            ->map(fn (array $rate) => [
                'courier' => (string) $rate['courier_code'],
                'service' => (string) $rate['courier_service_code'],
                'label' => trim(($rate['courier_name'] ?? $rate['courier_code']).' '.($rate['courier_service_name'] ?? $rate['courier_service_code'])),
                'fee' => (int) $rate['price'],
                // Estimasi tiba dari Biteship, misalnya "1 - 2" dengan satuan days atau hours; hanya untuk tampilan.
                'etd' => (string) ($rate['shipment_duration_range'] ?? ''),
                'etd_unit' => ($rate['shipment_duration_unit'] ?? 'days') === 'hours' ? 'hours' : 'days',
            ])
            ->values()
            ->all();
    }
}
