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
            'orders.view', 'orders.cancel_final', 'shipments.view', 'payments.view',
            'refunds.view', 'refunds.decide', 'comments.create',
        ],
        'store-admin' => [
            'products.view', 'products.manage', 'vouchers.view', 'vouchers.manage', 'stock.view', 'stock.adjust',
            'orders.view', 'orders.update_status', 'orders.cancel', 'shipments.view', 'shipments.manage',
            'payments.view', 'settings.manage_contact', 'reviews.manage', 'comments.create',
        ],
        'customer-service' => [
            'products.view', 'stock.view', 'orders.view', 'orders.cancel_request', 'shipments.view',
            'refunds.view', 'refunds.process', 'comments.create',
        ],
        'warehouse' => [
            'products.view', 'stock.view', 'stock.adjust', 'orders.view_fulfillment', 'orders.update_status',
            'shipments.view', 'shipments.manage', 'comments.create',
        ],
        'marketing' => [
            'reports.view_summary', 'products.view', 'vouchers.view', 'vouchers.manage',
            'content.view', 'content.manage', 'comments.create',
        ],
    ];

    /** @var list<string> */
    private const SUPER_ADMIN_ONLY = ['users.manage', 'roles.manage', 'users.manage_mfa', 'settings.manage'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = array_unique([...self::SUPER_ADMIN_ONLY, ...array_merge(...array_values(self::ROLES))]);
        $permissions = array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), $names);

        Role::findOrCreate('super-admin', 'web')->syncPermissions($permissions);

        foreach (self::ROLES as $role => $rolePermissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
