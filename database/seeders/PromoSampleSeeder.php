<?php

namespace Database\Seeders;

use App\Models\ProductVariant;
use App\Models\Voucher;
use Illuminate\Database\Seeder;

/**
 * Data CONTOH voucher dan diskon varian untuk mencoba checkout dan dashboard di lokal. Bukan keputusan bisnis: angka dan kode
 * di bawah hanya contoh, silakan diubah dari dashboard (menu Promo). Tidak dipanggil DatabaseSeeder; jalankan dengan
 * php artisan db:seed --class=PromoSampleSeeder (setelah CatalogDraftSeeder bila ingin contoh diskon varian).
 * Aman dijalankan ulang: voucher dikenali dari kodenya dan diskon dari varian, audiens, jenis, dan nilainya, jadi suntingan
 * dashboard tidak tertimpa. Sengaja TIDAK membuat voucher hadiah pendaftaran (`signup_grant`), karena itu langsung terkirim ke
 * akun baru dan nilainya keputusan bisnis (D-126).
 */
class PromoSampleSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const VOUCHERS = [
        ['code' => 'HEMAT10', 'description' => 'Diskon 10% untuk semua pelanggan', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 100000, 'max_discount' => 25000, 'audience' => 'all', 'quota' => 100],
        ['code' => 'POTONG15K', 'description' => 'Potongan Rp15.000 untuk belanja mulai Rp150.000', 'type' => 'fixed', 'value' => 15000, 'min_subtotal' => 150000, 'audience' => 'all', 'quota' => 100],
        ['code' => 'TAMU5K', 'description' => 'Potongan Rp5.000 untuk pelanggan tanpa akun', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 50000, 'audience' => 'guest', 'quota' => 200],
        ['code' => 'MEMBER20', 'description' => 'Diskon 20% khusus member, perlu diklaim', 'type' => 'percent', 'value' => 20, 'min_subtotal' => 200000, 'max_discount' => 50000, 'audience' => 'member', 'quota' => 50, 'requires_claim' => true],
        ['code' => 'BARENG5K', 'description' => 'Bisa digabung dengan voucher lain', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 75000, 'audience' => 'all', 'quota' => 100, 'stackable' => true],
        ['code' => 'ONGKIR10K', 'description' => 'Gratis ongkir hingga Rp10.000, khusus member', 'type' => 'fixed', 'kind' => 'shipping', 'value' => 10000, 'min_subtotal' => 100000, 'audience' => 'member', 'quota' => 100, 'limit_period' => 'month'],
    ];

    /** @var list<array{sku: string, audience: string, type: string, value: int}> */
    private const DISCOUNTS = [
        ['sku' => 'NAS-TOP-01', 'audience' => 'all', 'type' => 'percent', 'value' => 10],
        ['sku' => 'MAC-MIN-31', 'audience' => 'member', 'type' => 'fixed', 'value' => 5000],
        ['sku' => 'BRW-BOX-03', 'audience' => 'guest', 'type' => 'percent', 'value' => 15],
    ];

    public function run(): void
    {
        $vouchers = 0;
        foreach (self::VOUCHERS as $row) {
            $voucher = Voucher::firstOrCreate(['code' => $row['code']], $row + [
                'kind' => Voucher::KIND_ORDER, 'per_customer_limit' => 1, 'is_active' => true, 'is_listed' => true,
                'starts_at' => now(), 'ends_at' => now()->addDays(30),
            ]);
            $vouchers += $voucher->wasRecentlyCreated ? 1 : 0;
        }

        $discounts = 0;
        $missing = 0;
        foreach (self::DISCOUNTS as $row) {
            $variant = ProductVariant::where('sku', $row['sku'])->first();
            if (! $variant) {
                $missing++;

                continue;
            }

            $discount = $variant->discounts()->firstOrCreate(
                ['audience' => $row['audience'], 'type' => $row['type'], 'value' => $row['value']],
                ['starts_at' => now(), 'ends_at' => now()->addDays(30), 'is_active' => true],
            );
            $discounts += $discount->wasRecentlyCreated ? 1 : 0;
        }

        $this->command?->info("{$vouchers} voucher dan {$discounts} diskon varian contoh dibuat.");
        if ($missing) {
            $this->command?->warn("{$missing} diskon dilewati karena SKU-nya belum ada (jalankan CatalogDraftSeeder dulu).");
        }
    }
}
