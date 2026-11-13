<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Matriks D-041 dan D-045 (docs/product/BUSINESS_RULES.md). super-admin memegang semua izin.
     *
     * @var array<string, list<string>>
     */
    private const ROLES = [
        'owner' => [
            'audit.view', 'reports.view', 'products.view', 'vouchers.view', 'content.view', 'stock.view',
            'customers.view', 'customers.deactivate',
            'orders.view', 'orders.cancel_final', 'shipments.view', 'payments.view',
            'refunds.view', 'refunds.decide', 'comments.create', 'news.view', 'marketing_drafts.approve',
        ],
        'store-admin' => [
            'customers.view', 'products.view', 'products.manage', 'categories.manage', 'campaigns.manage', 'vouchers.view', 'vouchers.manage', 'stock.view',
            'orders.view', 'orders.update_status', 'orders.cancel', 'shipments.view', 'shipments.manage', 'warehouses.manage',
            'payments.view', 'settings.manage_contact', 'reviews.manage', 'faqs.manage', 'terms.manage', 'comments.create', 'news.view',
        ],
        'customer-service' => [
            'products.view', 'stock.view', 'orders.view', 'orders.cancel_request', 'shipments.view',
            'customers.view', 'payments.view',
            'refunds.view', 'refunds.process', 'faqs.manage', 'comments.create', 'comments.cs', 'templates.manage', 'search.quick', 'followups.manage', 'emails.send', 'news.view',
        ],
        'warehouse' => [
            'products.view', 'stock.view', 'stock.adjust', 'orders.view_fulfillment', 'orders.update_status',
            'shipments.view', 'shipments.manage', 'warehouses.manage', 'comments.create', 'news.view',
        ],
        'marketing' => [
            'reports.view_summary', 'products.view', 'vouchers.view', 'vouchers.manage',
            'content.view', 'content.manage', 'comments.create', 'news.view', 'news.manage', 'marketing_drafts.manage',
        ],
    ];

    private const VIEW_REVIEWS = 'product_reviews.view';

    /** Semua peran boleh melihat draf konten AI Marketing; menyusunnya Marketing dan Super Admin, memutuskannya Pemilik dan Super Admin. */
    private const VIEW_DRAFTS = 'marketing_drafts.view';

    /** @var list<string> */
    private const SUPER_ADMIN_ONLY = ['users.manage', 'roles.manage', 'users.manage_mfa', 'settings.manage', 'product_reviews.manage'];

    /** Semua peran boleh melihat data gudang; mengubahnya hanya Super Admin, Admin Toko, dan Gudang (`warehouses.manage`). */
    private const VIEW_WAREHOUSES = 'warehouses.view';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Semua peran boleh melihat ulasan produk; hanya super-admin mengelolanya.
        $names = array_unique([...self::SUPER_ADMIN_ONLY, self::VIEW_REVIEWS, self::VIEW_WAREHOUSES, self::VIEW_DRAFTS, ...array_merge(...array_values(self::ROLES))]);
        $permissions = array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), $names);

        Role::findOrCreate('super-admin', 'web')->syncPermissions($permissions);

        foreach (self::ROLES as $role => $rolePermissions) {
            Role::findOrCreate($role, 'web')->syncPermissions([...$rolePermissions, self::VIEW_REVIEWS, self::VIEW_WAREHOUSES, self::VIEW_DRAFTS]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
