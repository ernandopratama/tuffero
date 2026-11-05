<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // OQ-23 no. 4: batas jumlah per pesanan, default 50, dapat diubah Admin Toko dan Super Admin.
            $table->unsignedSmallInteger('max_order_quantity')->default(50)->after('shelf_life_days');
        });

        Schema::table('products', function (Blueprint $table) {
            // OQ-23 no. 5: lencana bebas (mis. Terlaris), daftar teks pendek.
            $table->json('badges')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('badges'));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('max_order_quantity'));
    }
};
