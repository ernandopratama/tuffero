<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-120 (16 Nov 2026): batas stok menipis per varian ikut disamakan dengan batas global 30. Batas varian yang sudah diisi dikosongkan
 * (NULL = ikut Pengaturan `inventory.low_stock_threshold`), jadi Super Admin cukup mengubah satu angka di Pengaturan nanti.
 * Admin Toko masih bisa mengisi batas khusus per varian lagi di halaman produk. Angka lama tidak bisa dipulihkan oleh `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_variants')->whereNotNull('low_stock_threshold')->update(['low_stock_threshold' => null]);
    }

    public function down(): void
    {
        // Tidak ada yang dipulihkan: nilai per varian sebelumnya tidak disimpan.
    }
};
