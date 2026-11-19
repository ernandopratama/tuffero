<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Aplikasi terpasang (PWA) toko dan admin, D-144. Masing-masing punya saklar sendiri di Pengaturan (hanya Super Admin).
 * Manifest dan meta iOS hanya dikirim ke HTML untuk pengguna yang sudah masuk, jadi tamu tidak ditawari pemasangan.
 */
class PwaApp
{
    public const STORE = 'store';

    public const ADMIN = 'admin';

    public const KEYS = [
        self::STORE => 'pwa.store_enabled',
        self::ADMIN => 'pwa.admin_enabled',
    ];

    public static function enabled(string $app): bool
    {
        return Setting::where('key', self::KEYS[$app])->value('value') === '1';
    }

    /** Pelanggan (tanpa role) yang sudah masuk, dan aplikasi toko menyala. */
    public static function forCustomer(?User $user): bool
    {
        return $user !== null && ! $user->roles()->exists() && self::enabled(self::STORE);
    }

    /** Staf (punya role) yang sudah masuk, dan aplikasi admin menyala. */
    public static function forStaff(?User $user): bool
    {
        return $user !== null && $user->roles()->exists() && self::enabled(self::ADMIN);
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(string $app): array
    {
        $store = $app === self::STORE;
        $prefix = $store ? 'store' : 'admin';

        return [
            'id' => $store ? '/' : '/admin/',
            'name' => $store ? 'Tuffero Bakery' : 'Tuffero Admin',
            'short_name' => $store ? 'Tuffero' : 'Tuffero Admin',
            'description' => $store ? 'Belanja nastar, macaron, dan kue kering Tuffero Bakery Malang.' : 'Dashboard pengelolaan toko Tuffero.',
            'lang' => 'id',
            'start_url' => $store ? '/?source=pwa' : '/admin?source=pwa',
            'scope' => $store ? '/' : '/admin/',
            'display' => 'standalone',
            'background_color' => $store ? '#F6F0E5' : '#2B1B14',
            'theme_color' => '#2B1B14',
            'icons' => [
                ['src' => "/images/pwa/{$prefix}-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "/images/pwa/{$prefix}-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "/images/pwa/{$prefix}-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }
}
