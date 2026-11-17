<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * D-134 (16 Nov 2026): role Gudang akan dilebur ke Admin Toko. Untuk sementara role Gudang tetap ada, tetapi Admin Toko diberi
 * semua izin Gudang yang belum dimilikinya (saat ini `orders.view_fulfillment` dan `stock.adjust`; mengubah D-084).
 * Hanya menambah; izin lain Admin Toko tidak disentuh.
 */
return new class extends Migration
{
    /** Izin yang ditambahkan migrasi ini menurut matriks saat ditulis; dipakai untuk rollback. */
    private const ADDED = ['orders.view_fulfillment', 'stock.adjust'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $storeAdmin = Role::where('name', 'store-admin')->where('guard_name', 'web')->first();
        $warehouse = Role::where('name', 'warehouse')->where('guard_name', 'web')->first();

        if ($storeAdmin && $warehouse) {
            $missing = $warehouse->permissions->pluck('name')->diff($storeAdmin->permissions->pluck('name'))->values()->all();
            if ($missing) {
                $storeAdmin->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'store-admin')->where('guard_name', 'web')->first()?->revokePermissionTo(self::ADDED);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
