<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Batas stok menipis 30 (D-120, rapat 14 Nov 2026; menggantikan 10 di D-055). Hanya nilai yang masih 10 (bawaan lama) yang diganti,
 * jadi angka yang sudah diubah Super Admin tidak tertimpa. Batas per varian (`product_variants.low_stock_threshold`) tidak disentuh:
 * itu pengecualian yang sengaja, misalnya hampers berstok kecil.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'inventory.low_stock_threshold')->where('value', '10')->update(['value' => '30', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'inventory.low_stock_threshold')->where('value', '30')->update(['value' => '10', 'updated_at' => now()]);
    }
};
