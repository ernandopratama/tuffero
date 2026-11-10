<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Maksimal 2 voucher per pesanan (D-078). Dua voucher hanya boleh bila keduanya ditandai boleh digabung (`stackable`).
    // Aditif: voucher lama tidak boleh digabung, pesanan lama punya satu kode.
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->boolean('stackable')->default(false)->after('requires_claim');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('voucher_code_2', 40)->nullable()->after('voucher_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('voucher_code_2'));
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn('stackable'));
    }
};
