<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // D-133: menyalakan blokir otomatis retur diajukan Super Admin atau Admin Toko dengan catatan, lalu disetujui Pemilik.
        Schema::create('return_block_rule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255);
            // pending, approved, rejected.
            $table->string('status', 10)->default('pending');
            // true selama menunggu, NULL setelah diputuskan: indeks unik di bawah membatasi satu pengajuan menunggu.
            $table->boolean('pending_flag')->nullable()->default(true)->unique();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 255)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('permissions')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $permission = Permission::findOrCreate('customer_blocks.approve', 'web');

            foreach (['owner', 'super-admin'] as $name) {
                Role::where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_block_rule_requests');

        if (Schema::hasTable('permissions')) {
            Permission::where('name', 'customer_blocks.approve')->where('guard_name', 'web')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
