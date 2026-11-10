<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FAQ chat bisa tampil sebagai tombol saran di chat toko. Judul jadi teks tombol, jadi punya versi Inggris (opsional,
 * bila kosong chat memakai judul Indonesia) dan penanda is_suggested yang diatur admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_faqs', function (Blueprint $table) {
            $table->string('title_en', 120)->nullable()->after('title');
            $table->boolean('is_suggested')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('chat_faqs', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'is_suggested']);
        });
    }
};
