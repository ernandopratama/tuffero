<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Berita, update, dan promo manual untuk popup beranda toko (D-085). Kampanye masuk popup otomatis dari tabel campaigns. */
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('news');
            $table->string('title', 150);
            $table->string('title_en', 150)->nullable();
            $table->string('body', 300);
            $table->string('body_en', 300)->nullable();
            $table->string('image_path')->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->string('cta_label_en', 40)->nullable();
            $table->string('cta_url', 255)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
