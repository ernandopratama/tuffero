<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voucher di checkout (D-066, D-069, D-078, D-126). Semua dihitung di server: browser hanya mengirim kode.
 * Maksimal 2 voucher per pesanan: 2 voucher biasa (keduanya `stackable`), atau 1 voucher gratis ongkir + 1 voucher biasa.
 * Voucher biasa yang tidak `stackable` tetap boleh dipasangkan dengan voucher gratis ongkir (D-126).
 */
class VoucherService
{
    public const MAX_PER_ORDER = 2;

    /**
     * Potongan rupiah. Voucher biasa: dari subtotal (setelah diskon produk), tidak pernah melebihi subtotal.
     * Voucher gratis ongkir: nilai tetap, tidak pernah melebihi ongkir; ongkir belum diketahui (keranjang) = nilai voucher.
     */
    public function discountFor(Voucher $voucher, int $subtotal, ?int $shippingFee = null): int
    {
        if ($voucher->isShipping()) {
            return max(0, min($voucher->value, $shippingFee ?? $voucher->value));
        }

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
     * $province null = area belum diketahui (keranjang); area diperiksa pasti saat checkout.
     */
    public function problem(Voucher $voucher, int $subtotal, ?int $userId, ?string $phone, ?string $province = null): ?string
    {
        $now = now();

        if (! $voucher->is_active || ($voucher->starts_at && $voucher->starts_at->gt($now)) || ($voucher->ends_at && $voucher->ends_at->lt($now))) {
            return 'voucher_invalid';
        }
        // Voucher milik kampanye hanya berlaku selama kampanyenya berjalan.
        if ($voucher->campaign_id && ! Campaign::live()->whereKey($voucher->campaign_id)->exists()) {
            return 'voucher_invalid';
        }
        // Voucher gratis ongkir hanya untuk member (D-126).
        if (($voucher->audience === 'member' || $voucher->isShipping()) && ! $userId) {
            return 'voucher_member_only';
        }
        if ($voucher->audience === 'guest' && $userId) {
            return 'voucher_guest_only';
        }
        if ($voucher->requires_claim && ! $this->isClaimed($voucher, $userId)) {
            return $voucher->signup_grant ? 'voucher_invalid' : 'voucher_not_claimed';
        }
        if ($voucher->quota !== null && $voucher->used_count >= $voucher->quota) {
            return 'voucher_exhausted';
        }
        // Gratis ongkir: satu nomor WhatsApp terverifikasi milik akun, bukan nomor yang diketik di checkout (D-126).
        if ($voucher->isShipping()) {
            $phone = $this->verifiedPhone($userId);
            if (! $phone) {
                return 'voucher_phone_unverified';
            }
        }
        if ($this->usedByCustomer($voucher, $userId, $phone) >= ($voucher->per_customer_limit ?? PHP_INT_MAX)) {
            return 'voucher_used';
        }
        if ($province !== null && $voucher->provinces && ! in_array(self::provinceKey($province), array_map(self::provinceKey(...), $voucher->provinces), true)) {
            return 'voucher_area';
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
    public function listFor(int $subtotal, ?int $userId, ?string $phone, ?int $shippingFee = null, ?string $province = null): array
    {
        return Voucher::where('is_active', true)->where('is_listed', true)
            ->orderBy('min_subtotal')->orderBy('id')
            ->get()
            ->map(function (Voucher $voucher) use ($subtotal, $userId, $phone, $shippingFee, $province) {
                $problem = $this->problem($voucher, $subtotal, $userId, $phone, $province);
                $discount = $this->discountFor($voucher, $subtotal, $shippingFee);
                if (! $problem && $this->overBudget($voucher, $discount)) {
                    $problem = 'voucher_exhausted';
                }
                if ($problem && $problem !== 'voucher_min_subtotal') {
                    return null;
                }

                return $this->row($voucher) + [
                    'eligible' => $problem === null,
                    'discount' => $problem === null ? $discount : 0,
                    'missing' => max(0, $voucher->min_subtotal - $subtotal),
                ];
            })
            ->filter()->values()->all();
    }

    /**
     * Voucher yang perlu diklaim, untuk halaman Voucher (gaya Shopee). Tamu boleh melihat; klaim butuh akun.
     * Voucher hadiah pendaftaran tidak tampil: hanya diberikan otomatis ke member baru.
     *
     * @return list<array<string, mixed>>
     */
    public function claimable(?int $userId, ?int $campaignId = null): array
    {
        $claimed = $userId ? VoucherClaim::where('user_id', $userId)->pluck('voucher_id')->all() : [];
        $now = now();

        return Voucher::where('is_active', true)->where('is_listed', true)->where('requires_claim', true)->where('signup_grant', false)->where('audience', '!=', 'guest')
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
        $phone = $this->verifiedPhone($userId);

        return VoucherClaim::where('user_id', $userId)->with('voucher')->latest('claimed_at')->get()
            ->map(function (VoucherClaim $claim) use ($userId, $now, $phone) {
                $voucher = $claim->voucher;
                $status = match (true) {
                    ! $voucher->is_active, (bool) $voucher->ends_at?->lt($now), (bool) $claim->expires_at?->lt($now), $voucher->quota !== null && $voucher->used_count >= $voucher->quota => 'expired',
                    $this->usedByCustomer($voucher, $userId, $voucher->isShipping() ? $phone : null) >= ($voucher->per_customer_limit ?? PHP_INT_MAX) => 'used',
                    default => 'available',
                };
                // Berakhir lebih dulu antara voucher dan klaim (voucher pendaftaran: 30 hari sejak daftar).
                $ends = collect([$voucher->ends_at, $claim->expires_at])->filter()->min();

                return ['ends_at' => $ends?->toIso8601String()] + $this->row($voucher) + ['status' => $status, 'claimed_at' => $claim->claimed_at->toIso8601String()];
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
            ! $voucher, ! $voucher->requires_claim, $voucher->signup_grant, ! $voucher->is_active, (bool) $voucher->starts_at?->gt($now), (bool) $voucher->ends_at?->lt($now) => 'voucher_invalid',
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

    /** Member baru: klaimkan voucher hadiah pendaftaran yang aktif, berlaku claim_valid_days hari sejak daftar (D-126). */
    public function grantSignup(User $user): void
    {
        $now = now();

        Voucher::where('signup_grant', true)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get()
            ->each(fn (Voucher $voucher) => VoucherClaim::insertOrIgnore([
                'voucher_id' => $voucher->id,
                'user_id' => $user->id,
                'claimed_at' => $now,
                'expires_at' => $voucher->claim_valid_days ? $now->copy()->addDays($voucher->claim_valid_days) : null,
            ]));
    }

    /** Mengetik kode voucher yang perlu klaim = klaim otomatis bagi member yang login (seperti Shopee). Bukan untuk voucher pendaftaran. */
    private function autoClaim(?Voucher $voucher, ?int $userId): void
    {
        if ($voucher && $userId && $voucher->requires_claim && ! $voucher->signup_grant && $voucher->is_active && $voucher->audience !== 'guest') {
            VoucherClaim::insertOrIgnore(['voucher_id' => $voucher->id, 'user_id' => $userId, 'claimed_at' => now()]);
        }
    }

    private function isClaimed(Voucher $voucher, ?int $userId): bool
    {
        return $userId !== null && VoucherClaim::where('voucher_id', $voucher->id)->where('user_id', $userId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function row(Voucher $voucher): array
    {
        return [
            'code' => $voucher->code,
            'description' => $voucher->description,
            'type' => $voucher->type,
            'kind' => $voucher->kind,
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
     * Periksa dan hitung voucher untuk subtotal ini. Urutan kode = urutan hitung: voucher biasa kedua dihitung dari sisa
     * setelah voucher pertama, jadi total potongan tidak pernah melebihi subtotal. Voucher gratis ongkir memotong ongkir.
     * Minimum belanja tiap voucher dibandingkan dengan subtotal penuh. Dengan $lock, baris voucher dikunci (urut id, tanpa kebuntuan) untuk checkout.
     *
     * @param  list<mixed>  $codes
     * @return list<array{voucher: Voucher, discount: int}>
     *
     * @throws ValidationException
     */
    public function apply(array $codes, int $subtotal, ?int $userId, ?string $phone, bool $lock = false, ?int $shippingFee = null, ?string $province = null): array
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
            $problem = $voucher ? $this->problem($voucher, $subtotal, $userId, $phone, $province) : 'voucher_invalid';
            if ($problem) {
                throw ValidationException::withMessages(['voucher_code' => $problem]);
            }
        }

        $shipping = array_filter($found, fn (Voucher $voucher) => $voucher->isShipping());
        $regular = array_filter($found, fn (Voucher $voucher) => ! $voucher->isShipping());
        if (count($shipping) > 1) {
            throw ValidationException::withMessages(['voucher_code' => 'voucher_shipping_limit']);
        }
        if (count($regular) > 1 && collect($regular)->contains(fn (Voucher $voucher) => ! $voucher->stackable)) {
            throw ValidationException::withMessages(['voucher_code' => 'voucher_not_stackable']);
        }

        $left = $subtotal;

        return array_map(function (Voucher $voucher) use (&$left, $shippingFee) {
            $discount = $this->discountFor($voucher, $left, $shippingFee);
            if (! $voucher->isShipping()) {
                $left -= $discount;
            }
            // D-126: sisa anggaran kurang dari potongan = voucher dianggap habis (potongan tidak dikecilkan).
            if ($this->overBudget($voucher, $discount)) {
                throw ValidationException::withMessages(['voucher_code' => 'voucher_exhausted']);
            }

            return ['voucher' => $voucher, 'discount' => $discount];
        }, $found);
    }

    /**
     * Cek kode tanpa memakainya. Dipakai tombol Pakai di checkout dan keranjang.
     * Ongkir dan provinsi dari browser hanya untuk pratinjau; checkout menghitung ulang dari tarif Biteship dan alamat yang dikirim.
     *
     * @param  list<string>  $codes
     * @return array{code: string|null, discount: int, shipping_discount: int, vouchers: list<array{code: string, kind: string, discount: int}>}
     */
    public function check(array $codes, int $subtotal, ?int $userId, ?string $phone, ?int $shippingFee = null, ?string $province = null): array
    {
        $rows = array_map(
            fn (array $row) => ['code' => $row['voucher']->code, 'kind' => $row['voucher']->kind, 'discount' => $row['discount']],
            $this->apply($codes, $subtotal, $userId, $phone, shippingFee: $shippingFee, province: $province),
        );
        $sum = fn (string $kind) => array_sum(array_column(array_filter($rows, fn (array $row) => $row['kind'] === $kind), 'discount'));

        return ['code' => $rows[0]['code'] ?? null, 'discount' => $sum(Voucher::KIND_ORDER), 'shipping_discount' => $sum(Voucher::KIND_SHIPPING), 'vouchers' => $rows];
    }

    /**
     * Pakai voucher untuk pesanan yang baru dibuat. Wajib di dalam transaksi checkout: baris voucher dikunci
     * supaya dua checkout bersamaan tidak melewati kuota atau anggaran.
     *
     * @param  list<string>  $codes
     * @return list<array{voucher: Voucher, discount: int}>
     */
    public function redeem(array $codes, int $subtotal, ?int $userId, string $phone, int $shippingFee, string $province): array
    {
        $rows = $this->apply($codes, $subtotal, $userId, $phone, lock: true, shippingFee: $shippingFee, province: $province);

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
            // Gratis ongkir dihitung per nomor terverifikasi akun (D-126), voucher lain per nomor pemesan.
            'customer_phone' => $voucher->isShipping() ? ($this->verifiedPhone($userId) ?? $order->customer_phone) : $order->customer_phone,
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

    /** Potongan yang sudah dipakai dan belum dilepas (pesanan batal atau kedaluwarsa tidak dihitung). */
    public function spent(Voucher $voucher): int
    {
        return (int) VoucherRedemption::where('voucher_id', $voucher->id)->whereNull('released_at')->sum('amount');
    }

    private function overBudget(Voucher $voucher, int $discount): bool
    {
        return $voucher->budget !== null && $this->spent($voucher) + $discount > $voucher->budget;
    }

    private function verifiedPhone(?int $userId): ?string
    {
        $user = $userId ? User::find($userId) : null;

        return $user && $user->phone && $user->phone_verified_at ? $user->phone : null;
    }

    /** Provinsi disimpan sebagai teks: bandingkan tanpa beda huruf besar kecil dan spasi ganda. */
    public static function provinceKey(string $province): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($province)));
    }

    private function usedByCustomer(Voucher $voucher, ?int $userId, ?string $phone): int
    {
        return VoucherRedemption::where('voucher_id', $voucher->id)->whereNull('released_at')
            // D-126: batas "per bulan" = bulan kalender, mulai tanggal 1.
            ->when($voucher->limit_period === 'month', fn ($query) => $query->where('created_at', '>=', now()->startOfMonth()))
            ->where(fn ($query) => $query
                ->when($userId, fn ($inner) => $inner->orWhere('user_id', $userId))
                ->when($phone, fn ($inner) => $inner->orWhere('customer_phone', $phone)))
            ->when(! $userId && ! $phone, fn ($query) => $query->whereRaw('1 = 0'))
            ->count();
    }
}
