<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pendukung notifikasi peristiwa: penanda pengingat pembatalan yang lewat batas (sekali per pengajuan)
 * dan pengaturan batas stok menipis (bawaan 10, sama dengan label dashboard, D-055).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_cancellation_requests', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('due_at');
        });

        DB::table('settings')->insertOrIgnore([
            'key' => 'inventory.low_stock_threshold',
            'value' => '10',
            'type' => 'int',
            'group' => 'inventory',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('order_cancellation_requests', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });

        DB::table('settings')->where('key', 'inventory.low_stock_threshold')->delete();
    }
};
