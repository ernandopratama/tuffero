<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda tindak lanjut Customer Service ("hubungi lagi besok") pada pesanan, refund, atau pembatalan.
 * Catatan internal pendek; bukan tiket dan tidak menyimpan percakapan pelanggan (D-080). Tidak ada hapus: ditandai selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->morphs('followable');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('note', 200);
            $table->date('due_on');
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['done_at', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
