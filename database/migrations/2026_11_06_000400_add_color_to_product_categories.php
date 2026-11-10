<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warna kategori di dashboard, dipilih Super Admin atau Admin Toko dari palet tetap (kunci, bukan kode warna bebas).
 * Kategori yang sudah ada diberi warna bergilir supaya langsung berbeda.
 */
return new class extends Migration
{
    private const PALETTE = ['gold', 'rose', 'orange', 'green', 'teal', 'blue', 'purple'];

    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->after('is_active');
        });

        DB::table('product_categories')->orderBy('sort_order')->orderBy('id')->pluck('id')->each(
            fn ($id, $index) => DB::table('product_categories')->where('id', $id)->update(['color' => self::PALETTE[$index % count(self::PALETTE)]]),
        );
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
