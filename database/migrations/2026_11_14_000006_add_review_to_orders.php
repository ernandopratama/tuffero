<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Anti-fraud D-121 (rapat 14 Nov 2026, D-132):
 * - Pesanan dengan total Rp1.000.000 ke atas ditandai untuk ditinjau Admin Toko, tenggat 1 hari kerja (`review_due_at`).
 * - Bila total belum dibayar satu nomor HP (termasuk pesanan baru) mencapai Rp3.000.000, pesanan menunggu persetujuan Admin Toko
 *   sebelum bisa dibayar (status `awaiting_approval`, ditolak = `rejected`). Menggantikan batas jumlah `antifraud.max_unpaid_orders`.
 * Izin baru: `orders.review` (setuju/tolak, Admin Toko) dan `orders.review_alert` (peringatan tinjau, Admin Toko dan Pemilik).
 */
return new class extends Migration
{
    private const NEW_SETTINGS = [
        ['key' => 'antifraud.review_min_total', 'value' => '1000000'],
        ['key' => 'antifraud.approval_min_unpaid', 'value' => '3000000'],
        ['key' => 'antifraud.review_open_hours', 'value' => '7'],
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('review_due_at')->nullable()->after('expires_at');
            $table->timestamp('reviewed_at')->nullable()->after('review_due_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable()->after('reviewed_by');
            $table->index(['reviewed_at', 'review_due_at']);
        });

        $now = now();
        foreach (self::NEW_SETTINGS as $setting) {
            DB::table('settings')->insertOrIgnore([...$setting, 'type' => 'int', 'group' => 'antifraud', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('settings')->where('key', 'antifraud.max_unpaid_orders')->delete();

        if (Schema::hasTable('permissions')) {
            $review = Permission::findOrCreate('orders.review', 'web');
            $alert = Permission::findOrCreate('orders.review_alert', 'web');
            Role::whereIn('name', ['store-admin', 'super-admin'])->get()->each(fn (Role $role) => $role->givePermissionTo($review, $alert));
            Role::where('name', 'owner')->get()->each(fn (Role $role) => $role->givePermissionTo($alert));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['reviewed_at', 'review_due_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_due_at', 'reviewed_at', 'review_note']);
        });

        DB::table('settings')->whereIn('key', array_column(self::NEW_SETTINGS, 'key'))->delete();
        $now = now();
        DB::table('settings')->insertOrIgnore(['key' => 'antifraud.max_unpaid_orders', 'value' => '3', 'type' => 'int', 'group' => 'antifraud', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);

        if (Schema::hasTable('permissions')) {
            Permission::whereIn('name', ['orders.review', 'orders.review_alert'])->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
