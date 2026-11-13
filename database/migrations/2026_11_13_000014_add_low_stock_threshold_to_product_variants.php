<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Null = ikut pengaturan global inventory.low_stock_threshold.
            $table->unsignedSmallInteger('low_stock_threshold')->nullable()->after('max_order_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('low_stock_threshold'));
    }
};
