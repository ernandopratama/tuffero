<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan metrik harian konten yang sudah tayang, diisi manual oleh tim marketing (rapat 14 Nov 2026 poin 28).
     * Angka kumulatif per tanggal ukur; satu catatan per draf, platform, dan tanggal. Aditif, tanpa mengubah tabel lama.
     */
    public function up(): void
    {
        Schema::create('marketing_draft_metrics', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('marketing_draft_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('post_url', 500);
            $table->date('measured_on');
            $table->unsignedBigInteger('views');
            $table->unsignedBigInteger('likes');
            $table->unsignedBigInteger('shares');
            $table->unsignedBigInteger('comments');
            $table->unsignedBigInteger('saves');
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('new_followers')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['marketing_draft_id', 'platform', 'measured_on'], 'mdm_draft_platform_day_unique');
            $table->index(['platform', 'measured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_draft_metrics');
    }
};
