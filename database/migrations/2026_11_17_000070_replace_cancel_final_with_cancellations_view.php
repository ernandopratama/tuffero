<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * D-123 / D-138 tahap 1: persetujuan pembatalan berhenti di Admin Toko. Izin `orders.cancel_final` (ACC Pemilik) dihapus, Pemilik
 * hanya melihat lewat izin baru `cancellations.view`, dan pengajuan yang menunggu ACC Pemilik (`store_approved`) kembali ke
 * `validated` supaya Admin Toko menyelesaikannya (kolom `store_approved_*` tetap sebagai jejak ACC sebelumnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $view = Permission::findOrCreate('cancellations.view', 'web');
            foreach (['owner', 'super-admin'] as $name) {
                Role::where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($view);
            }

            Permission::where('name', 'orders.cancel_final')->where('guard_name', 'web')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (Schema::hasTable('order_cancellation_requests')) {
            DB::table('order_cancellation_requests')->where('status', 'store_approved')->update(['status' => 'validated']);
        }
    }

    public function down(): void
    {
        // Status pengajuan yang sudah dikembalikan ke `validated` tidak bisa dipulihkan; hanya izinnya.
        if (Schema::hasTable('permissions')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $final = Permission::findOrCreate('orders.cancel_final', 'web');
            foreach (['owner', 'super-admin'] as $name) {
                Role::where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($final);
            }

            Permission::where('name', 'cancellations.view')->where('guard_name', 'web')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
