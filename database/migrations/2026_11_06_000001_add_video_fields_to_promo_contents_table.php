<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video di bagian "Di Balik Layar" (D-042, D-057): pengenal platform dan ID video dari tautan,
 * urutan tampil, dan hapus lunak. Hanya menambah kolom; data lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_contents', function (Blueprint $table) {
            $table->string('provider', 12)->nullable()->after('video_url');
            $table->string('external_id', 64)->nullable()->after('provider');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('external_id');
            $table->softDeletes();
            $table->index(['type', 'is_published', 'sort_order'], 'promo_contents_live_index');
        });
    }

    public function down(): void
    {
        Schema::table('promo_contents', function (Blueprint $table) {
            $table->dropIndex('promo_contents_live_index');
            $table->dropSoftDeletes();
            $table->dropColumn(['provider', 'external_id', 'sort_order']);
        });
    }
};
