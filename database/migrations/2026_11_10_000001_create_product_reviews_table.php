<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ulasan per produk dari pembeli setelah pesanan selesai. Satu ulasan per barang pesanan (order_item_id unik).
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('author_name', 100);
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_published', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
