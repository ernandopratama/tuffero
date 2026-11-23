<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Isi awal menurut docs/database/DATABASE.md dan D-043. Nilai yang sudah diubah dari dashboard tidak ditimpa.
     *
     * @var list<array{key: string, value: string|null, type: string, group: string, is_public: bool}>
     */
    private const SETTINGS = [
        ['key' => 'contact.whatsapp_number', 'value' => '+6281252507880', 'type' => 'string', 'group' => 'contact', 'is_public' => true],
        ['key' => 'contact.admin_email', 'value' => 'macaron.tuffero@gmail.com', 'type' => 'string', 'group' => 'contact', 'is_public' => true],
        ['key' => 'security.super_admin_whatsapp', 'value' => '+6285117452317', 'type' => 'string', 'group' => 'security', 'is_public' => true],
        ['key' => 'reviews.rating', 'value' => '4.6', 'type' => 'string', 'group' => 'reviews', 'is_public' => true],
        ['key' => 'reviews.count', 'value' => '28', 'type' => 'int', 'group' => 'reviews', 'is_public' => true],
        ['key' => 'antifraud.review_min_total', 'value' => '1000000', 'type' => 'int', 'group' => 'antifraud', 'is_public' => false],
        ['key' => 'antifraud.approval_min_unpaid', 'value' => '3000000', 'type' => 'int', 'group' => 'antifraud', 'is_public' => false],
        ['key' => 'antifraud.review_open_hours', 'value' => '7', 'type' => 'int', 'group' => 'antifraud', 'is_public' => false],
        ['key' => 'antifraud.max_orders_per_day', 'value' => '5', 'type' => 'int', 'group' => 'antifraud', 'is_public' => false],
        ['key' => 'antifraud.return_block_enabled', 'value' => '0', 'type' => 'int', 'group' => 'antifraud', 'is_public' => false],
        ['key' => 'antifraud.return_block_streak', 'value' => '3', 'type' => 'int', 'group' => 'antifraud', 'is_public' => false],
        ['key' => 'loyalty.repeat_order_count', 'value' => '5', 'type' => 'int', 'group' => 'loyalty', 'is_public' => false],
        ['key' => 'refund.claim_window_hours', 'value' => '168', 'type' => 'int', 'group' => 'refund', 'is_public' => false],
        ['key' => 'refund.return_window_days', 'value' => '3', 'type' => 'int', 'group' => 'refund', 'is_public' => false],
        ['key' => 'refund.review_sla_hours', 'value' => null, 'type' => 'int', 'group' => 'refund', 'is_public' => false],
        ['key' => 'refund.payout_days_max', 'value' => '7', 'type' => 'int', 'group' => 'refund', 'is_public' => false],
        ['key' => 'refund.terms_version', 'value' => null, 'type' => 'string', 'group' => 'refund', 'is_public' => false],
        ['key' => 'cancellation.process_days_max', 'value' => '7', 'type' => 'int', 'group' => 'cancellation', 'is_public' => false],
        ['key' => 'cancellation.auto_unpaid_enabled', 'value' => '1', 'type' => 'int', 'group' => 'cancellation', 'is_public' => false],
        ['key' => 'cancellation.auto_max_per_phone', 'value' => '2', 'type' => 'int', 'group' => 'cancellation', 'is_public' => false],
        ['key' => 'cancellation.auto_refund_enabled', 'value' => '0', 'type' => 'int', 'group' => 'cancellation', 'is_public' => false],
        ['key' => 'discount.max_percent', 'value' => '50', 'type' => 'int', 'group' => 'discount', 'is_public' => false],
        ['key' => 'checkout.reservation_minutes', 'value' => '60', 'type' => 'int', 'group' => 'checkout', 'is_public' => false],
        ['key' => 'inventory.low_stock_threshold', 'value' => '30', 'type' => 'int', 'group' => 'inventory', 'is_public' => false],
        ['key' => 'marketing.videos_per_week', 'value' => '2', 'type' => 'int', 'group' => 'marketing', 'is_public' => false],
        // D-144: aplikasi terpasang toko dan admin, bawaan mati; dinyalakan Super Admin.
        ['key' => 'pwa.store_enabled', 'value' => '0', 'type' => 'int', 'group' => 'pwa', 'is_public' => false],
        ['key' => 'pwa.admin_enabled', 'value' => '0', 'type' => 'int', 'group' => 'pwa', 'is_public' => false],
        ['key' => 'registration.whatsapp_required', 'value' => '0', 'type' => 'int', 'group' => 'registration', 'is_public' => false],
    ];

    public function run(): void
    {
        foreach (self::SETTINGS as $setting) {
            Setting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
