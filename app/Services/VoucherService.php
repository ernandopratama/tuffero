<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voucher di checkout (D-066, D-069, D-078). Semua dihitung di server: browser hanya mengirim kode.
 * Maksimal 2 voucher produk atau toko per pesanan, dan 2 hanya bila keduanya boleh digabung (`stackable`);
 * voucher ongkir menunggu versi berikutnya (D-068).
 */
class VoucherService
{
    public const MAX_PER_ORDER = 2;

    /** Potongan rupiah untuk subtotal ini (setelah diskon produk). Tidak pernah melebihi subtotal. */
    public function discountFor(Voucher $voucher, int $subtotal): int
    {
        $amount = $voucher->type === 'percent'
            ? intdiv($subtotal * $voucher->value, 100)
            : $voucher->value;

        if ($voucher->type === 'percent' && $voucher->max_discount) {
            $amount = min($amount, $voucher->max_discount);
        }

        return max(0, min($amount, $subtotal));
    }

    /**
     * Alasan voucher tidak bisa dipakai, atau null bila boleh. Kode alasan = kunci teks di toko.
     * Pemeriksaan pasti saat checkout terjadi di redeem(), dengan baris voucher dikunci.
     */
    public function problem(Voucher $voucher, int $subtotal, ?int $userId, ?string $phone): ?string
    {
        $now = now();

        if (! $voucher->is_active || ($voucher->starts_at && $voucher->starts_at->gt($now)) || ($voucher->ends_at && $voucher->ends_at->lt($now))) {
            return 'voucher_invalid';
        }
        // Voucher milik kampanye hanya berlaku selama kampanyenya berjalan.
        if ($voucher->campaign_id && ! Campaign::live()->whereKey($voucher->campaign_id)->exists()) {
            return 'voucher_invalid';
        }
        if ($voucher->audience === 'member' && ! $userId) {
            return 'voucher_member_only';
        }
        if ($voucher->audience === 'guest' && $userId) {
            return 'voucher_guest_only';
        }
        if ($voucher->requires_claim && ! $this->isClaimed($voucher, $userId)) {
            return 'voucher_not_claimed';
        }
        if ($voucher->quota !== null && $voucher->used_count >= $voucher->quota) {
            return 'voucher_exhausted';
        }
        if ($this->usedByCustomer($voucher, $userId, $phone) >= ($voucher->per_customer_limit ?? PHP_INT_MAX)) {
            return 'voucher_used';
        }
        if ($subtotal < $voucher->min_subtotal) {
            return 'voucher_min_subtotal';
        }

        return null;
    }

    /**
     * Voucher yang tampil di pilihan checkout (gaya Shopee): hanya yang berlaku untuk pelanggan ini.
     * Yang belum memenuhi minimum belanja tetap tampil, redup, dengan selisihnya.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $subtotal, ?int $userId, ?string $phone): array
    {
        return Voucher::where('is_active', true)->where('is_listed', true)
            ->orderBy('min_subtotal')->orderBy('id')
            ->get()
            ->map(function (Voucher $voucher) use ($subtotal, $userId, $phone) {
                $problem = $this->problem($voucher, $subtotal, $userId, $phone);
                if ($problem && $problem !== 'voucher_min_subtotal') {
                    return null;
                }

                return $this->row($voucher) + [
                    'eligible' => $problem === null,
                    'discount' => $problem === null ? $this->discountFor($voucher, $subtotal) : 0,
                    'missing' => max(0, $voucher->min_subtotal - $subtotal),
                ];
            })
            ->filter()->values()->all();
    }

    /**
     * Voucher yang perlu diklaim, untuk halaman Voucher (gaya Shopee). Tamu boleh melihat; klaim butuh akun.
     *
     * @return list<array<string, mixed>>
     */
    public function claimable(?int $userId, ?int $campaignId = null): array
    {
        $claimed = $userId ? VoucherClaim::where('user_id', $userId)->pluck('voucher_id')->all() : [];
        $now = now();

        return Voucher::where('is_active', true)->where('is_listed', true)->where('requires_claim', true)->where('audience', '!=', 'guest')
            ->when($campaignId, fn ($query) => $query->where('campaign_id', $campaignId))
            ->where(fn ($query) => $query->whereNull('campaign_id')->orWhereHas('campaign', fn ($campaign) => $campaign->live()))
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('ends_at')->orderBy('id')
            ->get()
            ->map(fn (Voucher $voucher) => $this->row($voucher) + [
                'claimed' => in_array($voucher->id, $claimed, true),
                'exhausted' => $voucher->quota !== null && $voucher->used_count >= $voucher->quota,
            ])
            ->values()->all();
    }

    /**
     * Voucher yang sudah diklaim akun ini ("Voucher Saya"). status: available, used (batas pakai tercapai), expired (berakhir, nonaktif, atau kuota habis).
     *
     * @return list<array<string, mixed>>
     */
    public function wallet(int $userId): array
    {
        $now = now();
        $rank = ['available' => 0, 'used' => 1, 'expired' => 2];

        return VoucherClaim::where('user_id', $userId)->with('voucher')->latest('claimed_at')->get()
            ->map(function (VoucherClaim $claim) use ($userId, $now) {
                $voucher = $claim->voucher;
                $status = match (true) {
                    ! $voucher->is_active, (bool) $voucher->ends_at?->lt($now), $voucher->quota !== null && $voucher->used_count >= $voucher->quota => 'expired',
                    $this->usedByCustomer($voucher, $userId, null) >= ($voucher->per_customer_limit ?? PHP_INT_MAX) => 'used',
                    default => 'available',
                };

                return $this->row($voucher) + ['status' => $status, 'claimed_at' => $claim->claimed_at->toIso8601String()];
            })
            ->sortBy(fn (array $row) => $rank[$row['status']])->values()->all();
    }

    /**
     * Klaim voucher ke akun. Aman diulang: klaim kedua tidak membuat baris baru.
     *
     * @throws ValidationException
     */
    public function claim(string $code, int $userId): Voucher
    {
        $voucher = $this->findByCode($code);
        $now = now();

        $problem = match (true) {
            ! $voucher, ! $voucher->requires_claim, ! $voucher->is_active, (bool) $voucher->starts_at?->gt($now), (bool) $voucher->ends_at?->lt($now) => 'voucher_invalid',
            $voucher->audience === 'guest' => 'voucher_guest_only',
            $voucher->quota !== null && $voucher->used_count >= $voucher->quota => 'voucher_exhausted',
            default => null,
        };
        if ($problem) {
            throw ValidationException::withMessages(['voucher_code' => $problem]);
        }

        VoucherClaim::insertOrIgnore(['voucher_id' => $voucher->id, 'user_id' => $userId, 'claimed_at' => $now]);

        return $voucher;
    }

    /** Mengetik kode voucher yang perlu klaim = klaim otomatis bagi member yang login (seperti Shopee). */
    private function autoClaim(?Voucher $voucher, ?int $userId): void
    {
        if ($voucher && $userId && $voucher->requires_claim && $voucher->is_active && $voucher->audience !== 'guest') {
            VoucherClaim::insertOrIgnore(['voucher_id' => $voucher->id, 'user_id' => $userId, 'claimed_at' => now()]);
        }
    }

    private function isClaimed(Voucher $voucher, ?int $userId): bool
    {
        return $userId !== null && VoucherClaim::where('voucher_id', $voucher->id)->where('user_id', $userId)->exists();
    }

    /** @return array<string, mixed> */
    private function row(Voucher $voucher): array
    {
        return [
            'code' => $voucher->code,
            'description' => $voucher->description,
            'type' => $voucher->type,
            'value' => $voucher->value,
            'max_discount' => $voucher->max_discount,
            'min_subtotal' => $voucher->min_subtotal,
            'ends_at' => $voucher->ends_at?->toIso8601String(),
            'stackable' => (bool) $voucher->stackable,
        ];
    }

    /** Cari voucher dari kode yang diketik pelanggan (tanpa membedakan huruf besar kecil). */
    public function findByCode(string $code): ?Voucher
    {
        return Voucher::where('code', strtoupper(trim($code)))->first();
    }

    /**
     * Periksa dan hitung voucher untuk subtotal ini. Urutan kode = urutan hitung: voucher kedua dihitung dari sisa
     * setelah voucher pertama, jadi total potongan tidak pernah melebihi subtotal. Minimum belanja tiap voucher
     * dibandingkan dengan subtotal penuh. Dengan $lock, baris voucher dikunci (urut id, tanpa kebuntuan) untuk checkout.
     *
     * @param  list<string>  $codes
     * @return list<array{voucher: Voucher, discount: int}>
     *
     * @throws ValidationException
     */
    public function apply(array $codes, int $subtotal, ?int $userId, ?string $phone, bool $lock = false): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($code) => is_string($code) ? strtoupper(trim($code)) : '', $codes))));

        if (count($codes) > self::MAX_PER_ORDER) {
            throw ValidationException::withMessages(['voucher_code' => 'voucher_limit']);
        }

        $found = array_map(fn (string $code) => $this->findByCode($code), $codes);
        if ($lock) {
            $locked = Voucher::whereIn('id', array_filter(array_map(fn (?Voucher $voucher) => $voucher?->id, $found)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $found = array_map(fn (?Voucher $voucher) => $voucher ? $locked->get($voucher->id) : null, $found);
        }

        foreach ($found as $voucher) {
            $this->autoClaim($voucher, $userId);
            $problem = $voucher ? $this->problem($voucher, $subtotal, $userId, $phone) : 'voucher_invalid';
            if ($problem) {
                throw ValidationException::withMessages(['voucher_code' => $problem]);
            }
        }

        if (count($found) > 1 && collect($found)->contains(fn (Voucher $voucher) => ! $voucher->stackable)) {
            throw ValidationException::withMessages(['voucher_code' => 'voucher_not_stackable']);
        }

        $left = $subtotal;

        return array_map(function (Voucher $voucher) use (&$left) {
            $discount = $this->discountFor($voucher, $left);
            $left -= $discount;

            return ['voucher' => $voucher, 'discount' => $discount];
        }, $found);
    }

    /**
     * Cek kode tanpa memakainya. Dipakai tombol Pakai di checkout dan keranjang.
     *
     * @param  list<string>  $codes
     * @return array{code: string|null, discount: int, vouchers: list<array{code: string, discount: int}>}
     */
    public function check(array $codes, int $subtotal, ?int $userId, ?string $phone): array
    {
        $rows = array_map(fn (array $row) => ['code' => $row['voucher']->code, 'discount' => $row['discount']], $this->apply($codes, $subtotal, $userId, $phone));

        return ['code' => $rows[0]['code'] ?? null, 'discount' => array_sum(array_column($rows, 'discount')), 'vouchers' => $rows];
    }

    /**
     * Pakai voucher untuk pesanan yang baru dibuat. Wajib di dalam transaksi checkout: baris voucher dikunci
     * supaya dua checkout bersamaan tidak melewati kuota.
     *
     * @param  list<string>  $codes
     * @return list<array{voucher: Voucher, discount: int}>
     */
    public function redeem(array $codes, int $subtotal, ?int $userId, string $phone): array
    {
        $rows = $this->apply($codes, $subtotal, $userId, $phone, lock: true);

        foreach ($rows as $row) {
            $row['voucher']->increment('used_count');
        }

        return $rows;
    }

    public function record(Voucher $voucher, Order $order, int $amount, ?int $userId): void
    {
        VoucherRedemption::create([
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'user_id' => $userId,
            'customer_phone' => $order->customer_phone,
            'amount' => $amount,
        ]);
    }

    /** Pesanan batal atau kedaluwarsa: kuota kembali dan pemakaian tidak lagi dihitung. Aman dipanggil berulang. */
    public function release(Order $order): void
    {
        DB::transaction(function () use ($order) {
            VoucherRedemption::where('order_id', $order->id)->whereNull('released_at')->lockForUpdate()->get()
                ->each(function (VoucherRedemption $redemption) {
                    $redemption->update(['released_at' => now()]);
                    Voucher::whereKey($redemption->voucher_id)->where('used_count', '>', 0)->decrement('used_count');
                });
        });
    }

    private function usedByCustomer(Voucher $voucher, ?int $userId, ?string $phone): int
    {
        return VoucherRedemption::where('voucher_id', $voucher->id)->whereNull('released_at')
            ->where(fn ($query) => $query
                ->when($userId, fn ($inner) => $inner->orWhere('user_id', $userId))
                ->when($phone, fn ($inner) => $inner->orWhere('customer_phone', $phone)))
            ->when(! $userId && ! $phone, fn ($query) => $query->whereRaw('1 = 0'))
            ->count();
    }
}
