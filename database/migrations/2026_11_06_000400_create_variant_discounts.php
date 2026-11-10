<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-070: diskon produk per varian. Harga jual varian tidak diubah; potongan dihitung di server saat harga ditampilkan dan dibayar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            // all = semua pelanggan, guest = Non Member (tanpa login), member = pelanggan yang login (D-066).
            $table->string('audience', 10)->default('all');
            $table->string('type', 10);
            $table->unsignedInteger('value');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['product_variant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_discounts');
    }
};
