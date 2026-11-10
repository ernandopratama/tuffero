<?php

namespace App\Services\Shipping;

use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Riwayat pelacakan paket per hub/kota dari Biteship, berdasarkan nomor resi + kode kurir (J&T, SiCepat).
 * Kunci API hanya di server (BR-20). Hanya hasil sukses disimpan di cache; galat mengembalikan null (riwayat tidak tersedia),
 * bukan daftar kosong yang menyesatkan. Panggilan dibatasi per hari untuk seluruh toko supaya saldo Biteship aman dari bot (D-076).
 */
class BiteshipTracker
{
    private const COURIERS = ['jnt', 'sicepat'];

    private const ACTIVE_MINUTES = 15;

    private const DONE_HOURS = 24;

    private const FAILURE_MINUTES = 2;

    public function enabled(): bool
    {
        return filled(config('services.biteship.key'));
    }

    /**
     * Riwayat tersimpan di shipment_events: halaman membaca dari database, Biteship hanya ditanya lewat history() (cache + batas harian)
     * dan hanya selama paket belum diterima. Terbaru lebih dulu; null bila belum ada riwayat sama sekali.
     *
     * @return list<array{note: string, status: string, at: string}>|null
     */
    public function sync(Shipment $shipment): ?array
    {
        $done = $shipment->status === 'delivered' && $shipment->events()->exists();

        if (! $done && $shipment->waybill_id && ($fetched = $this->history((string) $shipment->courier_code, $shipment->waybill_id))) {
            $now = now();
            $shipment->events()->upsert(
                array_map(fn (array $e) => [
                    'shipment_id' => $shipment->id,
                    'event_key' => substr(sha1($e['status'].'|'.$e['at'].'|'.$e['note']), 0, 40),
                    'status' => mb_substr($e['status'], 0, 30),
                    'note' => $e['note'],
                    'occurred_at' => CarbonImmutable::parse($e['at'])->utc()->format('Y-m-d H:i:s'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $fetched),
                ['shipment_id', 'event_key'],
                ['updated_at'],
            );
        }

        $events = $shipment->events()->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return $events->isEmpty() ? null : $events->map(fn ($e) => [
            'note' => $e->note,
            'status' => $e->status,
            'at' => $e->occurred_at->toIso8601String(),
        ])->all();
    }

    /**
     * Kejadian terbaru lebih dulu.
     *
     * @return list<array{note: string, status: string, at: string}>|null
     */
    public function history(string $courier, string $waybill): ?array
    {
        if (! $this->enabled() || ! in_array($courier, self::COURIERS, true) || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $waybill)) {
            return null;
        }

        $key = "biteship:tracking:{$courier}:{$waybill}";

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        if (Cache::has("{$key}:failed") || ! $this->spendDailyBudget()) {
            return null;
        }

        $events = $this->fetch($courier, $waybill);

        if ($events === null) {
            Cache::put("{$key}:failed", true, now()->addMinutes(self::FAILURE_MINUTES));

            return null;
        }

        $delivered = collect($events)->contains(fn (array $event) => $event['status'] === 'delivered');
        Cache::put($key, $events, $delivered ? now()->addHours(self::DONE_HOURS) : now()->addMinutes(self::ACTIVE_MINUTES));

        return $events;
    }

    /** @return list<array{note: string, status: string, at: string}>|null */
    private function fetch(string $courier, string $waybill): ?array
    {
        try {
            $response = Http::baseUrl((string) config('services.biteship.base_url'))
                ->withHeaders(['Authorization' => (string) config('services.biteship.key')])
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->get('/v1/trackings/'.rawurlencode($waybill).'/couriers/'.rawurlencode($courier));
        } catch (Throwable $e) {
            Log::warning('Biteship tracking request failed.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || $response->json('success') === false) {
            Log::warning('Biteship tracking rejected.', ['status' => $response->status(), 'error' => $response->json('error')]);

            return null;
        }

        return collect($response->json('history', []))
            ->filter(fn ($event) => is_array($event) && filled($event['note'] ?? null) && filled($event['updated_at'] ?? null))
            ->map(fn (array $event) => [
                'note' => mb_substr(strip_tags((string) $event['note']), 0, 300),
                'status' => (string) ($event['status'] ?? ''),
                'at' => CarbonImmutable::parse($event['updated_at'])->toIso8601String(),
            ])
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    /** Maksimal N panggilan ke Biteship per hari untuk seluruh toko (BITESHIP_DAILY_TRACKING_LIMIT, 0 = tanpa batas). */
    private function spendDailyBudget(): bool
    {
        $limit = (int) config('services.biteship.daily_tracking_limit');

        if ($limit <= 0) {
            return true;
        }

        $key = 'biteship:tracking:count:'.now()->toDateString();
        Cache::add($key, 0, now()->addDays(2));
        $used = (int) Cache::increment($key);

        if ($used > $limit && $used === $limit + 1) {
            Log::warning('Biteship daily tracking limit reached.', ['limit' => $limit]);
        }

        return $used <= $limit;
    }
}
