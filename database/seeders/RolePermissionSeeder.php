<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Matriks D-041 dan D-045 (docs/product/BUSINESS_RULES.md). super-admin memegang semua izin.
     * Seeder menyinkronkan role bawaan ke matriks ini (lihat `run()`); migrasi `2026_11_16_000010` juga membacanya sekali.
     *
     * @var array<string, list<string>>
     */
    private const ROLES = [
        'owner' => [
            'audit.view', 'reports.view', 'reports.export', 'products.view', 'vouchers.view', 'content.view', 'stock.view',
            'customers.view', 'customers.deactivate', 'customer_blocks.view', 'customer_blocks.approve',
            'orders.view', 'orders.review_alert', 'cancellations.view', 'shipments.view', 'payments.view',
            'refunds.view', 'refunds.decide', 'comments.create', 'news.view', 'marketing_drafts.approve',
        ],
        'store-admin' => [
            'customers.view', 'customer_blocks.view', 'customer_blocks.manage', 'products.view', 'products.manage', 'categories.manage', 'campaigns.manage', 'vouchers.view', 'vouchers.manage', 'stock.view',
            'orders.view', 'orders.review', 'orders.review_alert', 'orders.update_status', 'orders.complete', 'orders.cancel', 'shipments.view', 'shipments.manage', 'warehouses.manage',
            // D-134: semua izin Gudang juga dimiliki Admin Toko (role Gudang akan dilebur ke Admin Toko; mengubah D-084).
            'orders.view_fulfillment', 'stock.adjust',
            'payments.view', 'settings.manage_contact', 'reviews.manage', 'faqs.manage', 'terms.manage', 'comments.create', 'news.view',
            // D-150 (revisi 23 Nov 2026): Admin Toko dan Marketing mengisi metrik performa konten; Pemilik hanya melihat.
            'content_metrics.manage', 'social_metrics.manage',
        ],
        'customer-service' => [
            'products.view', 'stock.view', 'orders.view', 'orders.cancel_request', 'shipments.view',
            'customers.view', 'customer_blocks.view', 'payments.view',
            'refunds.view', 'refunds.process', 'faqs.manage', 'comments.create', 'comments.cs', 'templates.manage', 'search.quick', 'followups.manage', 'emails.send', 'news.view',
        ],
        'warehouse' => [
            'products.view', 'stock.view', 'stock.adjust', 'orders.view_fulfillment', 'orders.update_status',
            'shipments.view', 'shipments.manage', 'warehouses.manage', 'comments.create', 'news.view',
        ],
        'marketing' => [
            'reports.view_summary', 'products.view', 'vouchers.view', 'vouchers.manage',
            'content.view', 'content.manage', 'comments.create', 'news.view', 'news.manage', 'marketing_drafts.manage', 'content_metrics.manage', 'social_metrics.manage',
        ],
    ];

    private const VIEW_REVIEWS = 'product_reviews.view';

    /** Semua peran boleh melihat draf konten AI Marketing; menyusunnya Marketing dan Super Admin, memutuskannya Pemilik dan Super Admin. */
    private const VIEW_DRAFTS = 'marketing_drafts.view';

    /** Semua peran boleh melihat data gudang; mengubahnya hanya Super Admin, Admin Toko, dan Gudang (`warehouses.manage`). */
    private const VIEW_WAREHOUSES = 'warehouses.view';

    /**
     * Sinkron (diminta Ernando 16 Nov 2026, D-129): setiap seeder dijalankan, izin lima role bawaan dikembalikan persis ke matriks di
     * atas. **Perubahan izin role bawaan dari halaman Role dan Izin hilang saat seeder dijalankan ulang (mis. saat deploy)**, jadi
     * Super Admin mengatur ulang sesudahnya. Role kustom dan izin kustom tidak disentuh.
     */
    public function run(): void
    {
        $this->ensurePermissions();

        foreach (self::ROLES as $role => $rolePermissions) {
            Role::findOrCreate($role, 'web')->syncPermissions(self::defaults($rolePermissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Sekali jalan dari migrasi D-129: menyusulkan izin matriks yang belum dimiliki role bawaan (mis. izin fitur yang dulu hanya
     * diberikan lewat seeder), sebelum Super Admin mulai mengubah izin dari dashboard. Hanya menambah.
     */
    public function grantMissingDefaults(): void
    {
        $this->ensurePermissions();

        foreach (self::ROLES as $role => $rolePermissions) {
            $model = Role::findOrCreate($role, 'web');
            $missing = array_diff(self::defaults($rolePermissions), $model->permissions->pluck('name')->all());
            if ($missing) {
                $model->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensurePermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Semua izin di katalog dibuat, termasuk yang belum dimiliki role mana pun selain Super Admin.
        $names = array_keys(PermissionCatalog::PERMISSIONS);
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Super Admin selalu memegang semua izin, termasuk izin kustom (rolenya tidak bisa diubah dari dashboard).
        Role::findOrCreate(PermissionCatalog::SUPER_ADMIN, 'web')->syncPermissions(Permission::all());
    }

    /**
     * @param  list<string>  $rolePermissions
     * @return list<string>
     */
    private static function defaults(array $rolePermissions): array
    {
        return array_values(array_unique([...$rolePermissions, self::VIEW_REVIEWS, self::VIEW_WAREHOUSES, self::VIEW_DRAFTS]));
    }
}
