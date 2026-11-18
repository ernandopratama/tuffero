<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * D-143 tahap 2: ID publik acak untuk pengajuan pembatalan, penanda tindak lanjut, dan blokir pelanggan.
 * Refund tidak perlu kolom baru: rute admin memakai UUID `public_ref` yang sudah ada.
 * Pola sama dengan tahap 1: tambah nullable, isi baris kosong (aman diulang), lalu NOT NULL dan unik.
 */
return new class extends Migration
{
    private const TABLES = ['order_cancellation_requests', 'follow_ups', 'customer_blocks'];

    public function up(): void
    {
        // Nama tabel ditulis langsung (bukan lewat loop) supaya Larastan mengenali kolom public_id.
        if (! Schema::hasColumn('order_cancellation_requests', 'public_id')) {
            Schema::table('order_cancellation_requests', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('follow_ups', 'public_id')) {
            Schema::table('follow_ups', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('customer_blocks', 'public_id')) {
            Schema::table('customer_blocks', function (Blueprint $table) {
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
