<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Midtrans Snap (D-015, BR-11). Satu baris per notifikasi unik supaya notifikasi ulang tidak diproses dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Midtrans menolak order_id yang sama dua kali, jadi token Snap pertama dipakai ulang.
            $table->string('snap_token', 64)->nullable()->after('expires_at');
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('transaction_id', 64);
            $table->string('transaction_status', 30);
            $table->string('fraud_status', 20)->nullable();
            $table->string('payment_type', 40)->nullable();
            $table->unsignedInteger('gross_amount');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['transaction_id', 'transaction_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('snap_token');
        });
    }
};
