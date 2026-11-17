<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tag repeat order (D-121 butir 6, D-135): ambang jumlah pesanan selesai per nomor WhatsApp, bawaan 5, diatur di Pengaturan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore(['key' => 'loyalty.repeat_order_count', 'value' => '5', 'type' => 'int', 'group' => 'loyalty', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'loyalty.repeat_order_count')->delete();
    }
};
