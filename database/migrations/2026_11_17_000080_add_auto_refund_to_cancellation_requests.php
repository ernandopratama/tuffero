<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-138 tahap 4: hasil refund otomatis lewat Midtrans per pengajuan pembatalan (`done` atau `failed`; null = belum dicoba).
 * `auto_refund_error` hanya pesan singkat dari Midtrans, tanpa data pelanggan atau rahasia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_cancellation_requests', function (Blueprint $table) {
            $table->string('auto_refund_status', 16)->nullable()->after('refunded_at');
            $table->string('auto_refund_error', 190)->nullable()->after('auto_refund_status');
            $table->dateTime('auto_refund_at')->nullable()->after('auto_refund_error');
        });
    }

    public function down(): void
    {
        Schema::table('order_cancellation_requests', function (Blueprint $table) {
            $table->dropColumn(['auto_refund_status', 'auto_refund_error', 'auto_refund_at']);
        });
    }
};
