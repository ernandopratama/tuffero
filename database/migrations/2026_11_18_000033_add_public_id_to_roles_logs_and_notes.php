<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * D-143 tahap 4: ID publik acak untuk role dan izin (tabel Spatie), log audit, riwayat stok, catatan internal, dan
 * email CS. Role dan izin memakai model bawaan Spatie, jadi public_id diisi lewat event `creating` di
 * AppServiceProvider. Rujukan angka di dalam log audit lama (`auditable_id`, `changes`) tidak diubah.
 * Pola sama dengan tahap 1 sampai 3: tambah nullable, isi baris kosong (aman diulang), lalu NOT NULL dan unik.
 */
return new class extends Migration
{
    private const TABLES = ['roles', 'permissions', 'audit_logs', 'stock_movements', 'admin_comments', 'customer_emails'];

    public function up(): void
    {
        // Nama tabel ditulis langsung (bukan lewat loop) supaya Larastan mengenali kolom public_id.
        if (! Schema::hasColumn('roles', 'public_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('permissions', 'public_id')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('audit_logs', 'public_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('stock_movements', 'public_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('admin_comments', 'public_id')) {
            Schema::table('admin_comments', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('customer_emails', 'public_id')) {
            Schema::table('customer_emails', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }

        foreach (self::TABLES as $table) {
            self::backfill($table);

            // Aman diulang bila proses sebelumnya terhenti di tengah (DDL MySQL tidak bisa di-rollback).
            $hasIndex = Schema::hasIndex($table, "{$table}_public_id_unique");
            // Role dan izin dibuat seeder dengan event model dibisukan (WithoutModelEvents), jadi kolomnya tetap
            // boleh kosong; RoleService::fillPublicIds() mengisinya sebelum data tampil di admin.
            $required = ! in_array($table, ['roles', 'permissions'], true);
            Schema::table($table, function (Blueprint $t) use ($hasIndex, $required) {
                if ($required) {
                    $t->char('public_id', 26)->nullable(false)->change();
                }
                if (! $hasIndex) {
                    $t->unique('public_id');
                }
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropUnique(['public_id']);
                    $t->dropColumn('public_id');
                });
            }
        }
    }

    /** Isi hanya baris yang belum punya public_id; dipanggil ulang tidak mengubah yang sudah terisi. */
    public static function backfill(string $table): void
    {
        DB::table($table)->whereNull('public_id')->orderBy('id')->select('id')->chunkById(500, function ($rows) use ($table) {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->whereNull('public_id')->update(['public_id' => strtolower((string) Str::ulid())]);
            }
        });
    }
};
