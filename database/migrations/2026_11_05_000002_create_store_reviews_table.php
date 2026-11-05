<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OQ-23 no. 6: ulasan toko (mis. dari Google Maps) yang dikelola Admin Toko dan Super Admin. Bukan ulasan per produk.
        Schema::create('store_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('author_name', 100);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('body');
            $table->string('source', 30)->default('google');
            $table->date('reviewed_on')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['is_published', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_reviews');
    }
};
