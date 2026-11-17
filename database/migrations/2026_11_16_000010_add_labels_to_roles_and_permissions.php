<?php

use App\Support\PermissionCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRUD role dan izin oleh Super Admin (D-129). Role dan izin buatan Super Admin butuh nama tampilan sendiri; role dan izin bawaan
 * memakai label dari App\Support\PermissionCatalog. Super Admin dipastikan memegang semua izin yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        Schema::table($tables['roles'], function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('name');
        });

        Schema::table($tables['permissions'], function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('name');
            $table->string('description', 255)->nullable()->after('label');
        });

        // Sekali saja: izin matriks yang belum dimiliki role bawaan disusulkan, karena setelah ini seeder tidak lagi menyentuh role
        // yang sudah ada. Database tanpa role (instalasi baru sebelum seeder) dilewati; seeder yang akan mengisinya.
        if (Role::where('name', PermissionCatalog::SUPER_ADMIN)->exists()) {
            (new RolePermissionSeeder)->grantMissingDefaults();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = config('permission.table_names');

        Schema::table($tables['roles'], fn (Blueprint $table) => $table->dropColumn('label'));
        Schema::table($tables['permissions'], fn (Blueprint $table) => $table->dropColumn(['label', 'description']));
    }
};
