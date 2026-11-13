<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template balasan untuk Customer Service. Teks siap pakai dengan variabel seperti {nama} dan {no_pesanan},
 * diisi server dari pesanan, refund, atau pengajuan pembatalan. Sistem tidak mengirim apa pun sendiri:
 * CS membuka WhatsApp atau menyalin teksnya. Tidak ada hapus, yang tak dipakai dinonaktifkan; perubahan masuk audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('scope', 20)->default('any');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'scope', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_templates');
    }
};
