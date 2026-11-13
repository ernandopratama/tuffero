<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan webhook Midtrans dan Biteship yang ditolak, untuk penanda keamanan di dashboard super-admin.
 * Hanya penyedia, alasan, IP, dan waktu. Tidak menyimpan payload, tanda tangan, atau rahasia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_rejections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('reason', 30);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_rejections');
    }
};
