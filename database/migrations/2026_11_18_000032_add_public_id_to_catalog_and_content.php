<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * D-143 tahap 3: ID publik acak untuk katalog dan konten admin (kategori, jenis, kampanye, gudang, voucher, konten promo,
 * galeri, berita, draf marketing beserta catatan dan foto acuannya, ulasan toko dan produk, FAQ, template balasan,
 * S&K/Kebijakan Privasi, dan pengajuan aturan blokir). Pola sama dengan tahap 1 dan 2: tambah nullable, isi baris
 * kosong (aman diulang), lalu NOT NULL dan unik; `down()` hanya membuang kolom.
 */
return new class extends Migration
{
    private const TABLES = [
        'product_categories',
        'product_types',
        'campaigns',
        'warehouses',
        'vouchers',
        'promo_contents',
        'gallery_photos',
        'news_items',
        'marketing_drafts',
        'marketing_draft_notes',
        'marketing_draft_references',
        'store_reviews',
        'product_reviews',
        'chat_faqs',
        'reply_templates',
        'terms_sections',
        'return_block_rule_requests',
    ];

    public function up(): void
    {
        // Nama tabel ditulis langsung (bukan lewat loop) supaya Larastan mengenali kolom public_id.
        if (! Schema::hasColumn('product_categories', 'public_id')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('product_types', 'public_id')) {
            Schema::table('product_types', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('campaigns', 'public_id')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('warehouses', 'public_id')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('vouchers', 'public_id')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('promo_contents', 'public_id')) {
            Schema::table('promo_contents', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('gallery_photos', 'public_id')) {
            Schema::table('gallery_photos', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('news_items', 'public_id')) {
            Schema::table('news_items', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('marketing_drafts', 'public_id')) {
            Schema::table('marketing_drafts', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('marketing_draft_notes', 'public_id')) {
            Schema::table('marketing_draft_notes', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('marketing_draft_references', 'public_id')) {
            Schema::table('marketing_draft_references', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('store_reviews', 'public_id')) {
            Schema::table('store_reviews', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('product_reviews', 'public_id')) {
            Schema::table('product_reviews', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('chat_faqs', 'public_id')) {
            Schema::table('chat_faqs', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('reply_templates', 'public_id')) {
            Schema::table('reply_templates', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('terms_sections', 'public_id')) {
            Schema::table('terms_sections', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('return_block_rule_requests', 'public_id')) {
            Schema::table('return_block_rule_requests', function (Blueprint $table) {
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
