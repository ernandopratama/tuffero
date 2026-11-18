<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-139: Kebijakan Privasi memakai tabel yang sama dengan Syarat dan Ketentuan, dibedakan kolom `type` ('terms' | 'privacy').
 * Tidak destruktif: baris lama otomatis 'terms'. Versi Privasi di setting `privacy.version` (dibuat saat perubahan pertama),
 * terpisah dari `refund.terms_version` yang dicatat di pengajuan refund. Izin tetap `terms.manage` (Admin Toko dan Super Admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms_sections', function (Blueprint $table) {
            $table->string('type', 20)->default('terms')->after('id');
            $table->index(['type', 'is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('terms_sections', function (Blueprint $table) {
            $table->dropIndex(['type', 'is_published', 'sort_order']);
            $table->dropColumn('type');
        });
    }
};
