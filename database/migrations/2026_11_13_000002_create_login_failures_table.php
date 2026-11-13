<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan login yang gagal, untuk penanda keamanan di dashboard super-admin. Email disimpan sebagai hash (bukan teks)
 * dan tidak ada password; yang tercatat hanya jumlah dan sebaran percobaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_failures', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_failures');
    }
};
