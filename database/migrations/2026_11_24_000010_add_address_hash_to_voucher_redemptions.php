<?php

use App\Models\CustomerBlock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-147: pemakaian voucher untuk Non Member juga dihitung per alamat pengiriman (jalan dan kode pos, dinormalkan, di-hash HMAC
 * seperti address_hash blokir retur D-122). Pemakaian yang sudah ada diisi dari alamat pesanannya supaya ikut terhitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->string('address_hash', 64)->nullable()->after('customer_phone');
            $table->index(['voucher_id', 'address_hash']);
        });

        // Alamat tanpa jalan atau kode pos terlalu umum untuk dicocokkan, jadi hash-nya tetap null.
        DB::table('voucher_redemptions')
            ->join('orders', 'orders.id', '=', 'voucher_redemptions.order_id')
            ->select('voucher_redemptions.id', 'orders.shipping_address')
            ->orderBy('voucher_redemptions.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $hash = CustomerBlock::addressHash((array) json_decode((string) $row->shipping_address, true));

                    if ($hash) {
                        DB::table('voucher_redemptions')->where('id', $row->id)->update(['address_hash' => $hash]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->dropIndex(['voucher_id', 'address_hash']);
            $table->dropColumn('address_hash');
        });
    }
};
