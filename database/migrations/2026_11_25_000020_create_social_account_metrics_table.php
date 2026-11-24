<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performa akun media sosial (D-151 tahap 1): satu catatan per platform dan periode (1, 7, atau 30 hari), diisi manual
     * dari layar insight akun. Semua angka boleh kosong kecuali total followers; kosong berarti tidak tersedia, bukan 0.
     * Angka unik (reach, engaged, unique viewers) tidak dijumlah antarhari, jadi tiap periode punya catatannya sendiri.
     * Aditif, tanpa mengubah tabel lama.
     */
    public function up(): void
    {
        Schema::create('social_account_metrics', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('platform', 20);
            $table->unsignedTinyInteger('period_days');
            $table->date('period_end');
            $table->date('measured_on');
            $table->string('source', 20)->default('manual');
            $table->boolean('is_test')->default(false);

            $table->unsignedBigInteger('followers_total');
            $table->unsignedBigInteger('followers_gained')->nullable();
            $table->unsignedBigInteger('followers_lost')->nullable();
            // Instagram: views. TikTok: video views.
            $table->unsignedBigInteger('views')->nullable();
            // Instagram: profile visits. TikTok: profile views.
            $table->unsignedBigInteger('profile_visits')->nullable();

            // Hanya Instagram.
            $table->unsignedBigInteger('accounts_reached')->nullable();
            $table->unsignedBigInteger('accounts_engaged')->nullable();
            $table->unsignedBigInteger('interactions')->nullable();
            $table->unsignedBigInteger('link_taps')->nullable();

            // Hanya TikTok.
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('total_viewers')->nullable();
            $table->unsignedBigInteger('unique_viewers')->nullable();
            $table->unsignedBigInteger('watch_time_seconds')->nullable();
            $table->unsignedInteger('avg_watch_seconds')->nullable();
            $table->unsignedBigInteger('account_likes_total')->nullable();

            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'period_days', 'period_end', 'is_test'], 'sam_platform_period_unique');
            $table->index(['platform', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_metrics');
    }
};
