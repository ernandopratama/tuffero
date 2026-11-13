<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat email yang dikirim CS ke pelanggan dari dashboard (D-108). Hanya jejak: siapa, kapan, ke alamat yang disamarkan,
 * subjek, dan hasilnya. Isi email tidak disimpan (D-080). Tidak ada hapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_emails', function (Blueprint $table) {
            $table->id();
            $table->morphs('emailable');
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->string('to_masked', 190);
            $table->string('subject', 150);
            $table->string('status', 10);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_emails');
    }
};
