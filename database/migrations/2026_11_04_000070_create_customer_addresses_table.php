<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            // Alamat ikut terhapus bersama akun. Pesanan menyimpan snapshot sendiri (D-051).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50);
            $table->string('recipient_name', 120);
            $table->string('phone', 20);
            $table->string('street', 255);
            $table->string('district', 100);
            $table->string('city', 100);
            $table->string('province', 100);
            $table->char('postal_code', 5);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
