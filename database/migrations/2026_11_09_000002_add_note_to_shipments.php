<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Catatan admin untuk resi manual (tampil di detail pesanan dan label). Nullable: data lama tidak berubah. */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('note', 300)->nullable()->after('waybill_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('note'));
    }
};
