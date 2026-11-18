<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * D-143 tahap 1: ID publik acak untuk produk (dan varian, foto, diskon) serta akun (pelanggan dan staf).
 * Tidak destruktif: kolom ditambah nullable, diisi untuk baris lama (hanya yang masih kosong, jadi aman diulang),
 * baru kemudian NOT NULL dan unik. `down()` hanya membuang kolom; `id` dan foreign key tidak disentuh.
 */
return new class extends Migration
{
    private const TABLES = ['products', 'product_variants', 'product_images', 'variant_discounts', 'users'];

    public function up(): void
    {
        // Nama tabel ditulis langsung (bukan lewat loop) supaya Larastan mengenali kolom public_id.
        if (! Schema::hasColumn('products', 'public_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('product_variants', 'public_id')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('product_images', 'public_id')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('variant_discounts', 'public_id')) {
            Schema::table('variant_discounts', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('users', 'public_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }

        foreach (self::TABLES as $table) {
            self::backfill($table);

            // Aman diulang bila proses sebelumnya terhenti di tengah (DDL MySQL tidak bisa di-rollback).
            $hasIndex = Schema::hasIndex($table, "{$table}_public_id_unique");
            Schema::table($table, function (Blueprint $t) use ($hasIndex) {
                $t->char('public_id', 26)->nullable(false)->change();
                if (! $hasIndex) {
                    $t->unique('public_id');
                }
            });
        }
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
