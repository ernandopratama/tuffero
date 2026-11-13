<?php

namespace App\Services;

use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Models\User;
use App\Support\StoreSite;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Angka layanan untuk Ringkasan Customer Service (D-106): jam operasional toko dan waktu proses refund serta pembatalan
 * 30 hari terakhir. Hanya membaca data yang sudah ada (tanpa tabel baru) dan tidak memuat nama atau kontak pelanggan.
 */
class ServiceStatsService
{
    public const WINDOW_DAYS = 30;

    private const DAY_NAMES = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    /**
     * Status jam operasional toko (Asia/Jakarta, StoreSite::OPEN_HOURS) dan kapan buka berikutnya bila sedang tutup.
     *
     * @return array{open: bool, text: string}
     */
    public function storeHours(CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Asia/Jakarta');
        $day = $now->dayOfWeekIso - 1;
        $today = StoreSite::OPEN_HOURS[$day];

        if ($today !== null && $now->hour >= $today[0] && $now->hour < $today[1]) {
            return ['open' => true, 'text' => sprintf('Toko buka, tutup pukul %02d.00 WIB', $today[1])];
        }

        // Belum buka hari ini, atau sudah tutup: cari hari buka berikutnya dalam seminggu.
        for ($ahead = 0; $ahead <= 7; $ahead++) {
            $index = ($day + $ahead) % 7;
            $hours = StoreSite::OPEN_HOURS[$index];
            if ($hours === null || ($ahead === 0 && $now->hour >= $hours[1])) {
                continue;
            }
            $label = $ahead === 0 ? 'hari ini' : ($ahead === 1 ? 'besok' : self::DAY_NAMES[$index]);

            return ['open' => false, 'text' => sprintf('Toko tutup, buka %s pukul %02d.00 WIB', $label, $hours[0])];
        }

        return ['open' => false, 'text' => 'Toko tutup'];
    }

    /**
     * Kartu angka per jenis data yang boleh dilihat pengguna. Kosong bila tidak punya izin satu pun.
     *
     * @return list<array{key: string, label: string, value: string, foot: string}>
     */
    public function tiles(User $user, CarbonImmutable $now): array
    {
        $since = $now->subDays(self::WINDOW_DAYS);
        $tiles = [];

        if ($user->can('refunds.view')) {
            $done = Refund::whereIn('status', [Refund::REFUNDED, Refund::REJECTED])->get()
                ->map(fn (Refund $r) => ['at' => $r->status === Refund::REFUNDED ? $r->refunded_at : $r->decided_at, 'from' => $r->created_at, 'due' => $r->due_at, 'rejected' => $r->status === Refund::REJECTED])
                ->filter(fn (array $r) => $r['at'] !== null && $r['at']->gte($since));
            $contacted = Refund::whereNotNull('contacted_at')->where('contacted_at', '>=', $since)->get(['created_at', 'contacted_at']);

            $tiles[] = $this->tile('refund_done', 'Refund selesai', $done->count(), $done->where('rejected', true)->count().' ditolak');
            $tiles[] = $this->tile('refund_first', 'Respons pertama refund', $this->averageHours($contacted->map(fn (Refund $r) => [$r->created_at, $r->contacted_at])), $contacted->count().' pengajuan dihubungi', true);
            $tiles[] = $this->tile('refund_on_time', 'Refund tepat waktu', $this->onTimePercent($done), 'dari '.$done->count().' yang selesai');
        }

        if ($user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'])) {
            $validated = OrderCancellationRequest::whereNotNull('validated_at')->where('validated_at', '>=', $since)->get(['created_at', 'validated_at']);
            $done = OrderCancellationRequest::whereIn('status', [OrderCancellationRequest::APPROVED, OrderCancellationRequest::REJECTED, OrderCancellationRequest::REFUNDED])->get()
                ->map(fn (OrderCancellationRequest $r) => ['at' => $r->refunded_at ?? $r->rejected_at ?? $r->owner_approved_at ?? $r->store_approved_at ?? $r->updated_at, 'from' => $r->created_at, 'due' => $r->due_at])
                ->filter(fn (array $r) => $r['at']->gte($since));

            $tiles[] = $this->tile('cancel_first', 'Respons pertama pembatalan', $this->averageHours($validated->map(fn (OrderCancellationRequest $r) => [$r->created_at, $r->validated_at])), $validated->count().' pengajuan divalidasi', true);
            $tiles[] = $this->tile('cancel_on_time', 'Pembatalan tepat waktu', $this->onTimePercent($done), 'dari '.$done->count().' yang selesai');
        }

        return $tiles;
    }

    /**
     * @param  int|float|string|null  $value  angka (jumlah atau jam) atau persen bila sudah berupa teks
     * @return array{key: string, label: string, value: string, foot: string}
     */
    private function tile(string $key, string $label, int|float|string|null $value, string $foot, bool $hours = false): array
    {
        if ($value === null) {
            return ['key' => $key, 'label' => $label, 'value' => '-', 'foot' => 'Belum ada data 30 hari terakhir'];
        }

        return ['key' => $key, 'label' => $label, 'value' => $hours ? $this->duration((float) $value) : (string) $value, 'foot' => $foot];
    }

    /**
     * Rata-rata jam antara dua waktu; null bila tidak ada data.
     *
     * @param  iterable<array{0: CarbonInterface|null, 1: CarbonInterface|null}>  $pairs
     */
    private function averageHours(iterable $pairs): ?float
    {
        $hours = [];

        foreach ($pairs as [$from, $to]) {
            if ($from !== null && $to !== null) {
                $hours[] = max(0, $from->diffInMinutes($to, true)) / 60;
            }
        }

        return $hours === [] ? null : array_sum($hours) / count($hours);
    }

    /** @param  iterable<array{at: CarbonInterface, due: CarbonInterface, ...}>  $done */
    private function onTimePercent(iterable $done): ?string
    {
        $total = 0;
        $onTime = 0;

        foreach ($done as $r) {
            $total++;
            $onTime += $r['at']->lte($r['due']) ? 1 : 0;
        }

        return $total === 0 ? null : round($onTime / $total * 100).'%';
    }

    private function duration(float $hours): string
    {
        return match (true) {
            $hours < 1 => max(1, (int) round($hours * 60)).' menit',
            $hours < 48 => number_format($hours, 1, ',', '').' jam',
            default => number_format($hours / 24, 1, ',', '').' hari',
        };
    }
}
