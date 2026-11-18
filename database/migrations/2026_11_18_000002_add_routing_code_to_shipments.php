<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode sortir kurir dari respons pembuatan order Biteship (`courier.routing_code`). Di dokumentasi Biteship nilainya bisa kosong
 * (null) dan tidak dijelaskan kapan terisi, jadi kolom ini opsional; resi cetak menampilkannya hanya bila ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('routing_code', 64)->nullable()->after('waybill_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('routing_code'));
    }
};
