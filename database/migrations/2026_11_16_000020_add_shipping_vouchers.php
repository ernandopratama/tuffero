<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-126: voucher gratis ongkir untuk member. Aditif: voucher lama menjadi `order` (voucher biasa), pesanan lama tanpa potongan ongkir.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // order = voucher biasa (potong subtotal), shipping = gratis ongkir (potong ongkir).
            $table->string('kind', 10)->default('order')->after('type');
            // Daftar provinsi tempat voucher berlaku; null = semua wilayah.
            $table->json('provinces')->nullable()->after('stackable');
            // Batas total potongan (rupiah) yang ditanggung toko; null = tanpa batas.
            $table->unsignedBigInteger('budget')->nullable()->after('provinces');
            // null = batas pakai per pelanggan seumur voucher; month = per bulan kalender.
            $table->string('limit_period', 10)->nullable()->after('per_customer_limit');
            // Voucher hadiah pendaftaran: diklaimkan otomatis ke member baru, berlaku claim_valid_days hari sejak daftar.
            $table->boolean('signup_grant')->default(false)->after('budget');
            $table->unsignedSmallInteger('claim_valid_days')->nullable()->after('signup_grant');
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('claimed_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            // shipping_fee tetap tarif penuh dari kurir; potongan ongkir disimpan terpisah (D-068, D-126).
            $table->unsignedInteger('shipping_discount')->default(0)->after('shipping_fee');
            $table->string('shipping_voucher_code', 40)->nullable()->after('voucher_code_2');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['shipping_discount', 'shipping_voucher_code']));
        Schema::table('voucher_claims', fn (Blueprint $table) => $table->dropColumn('expires_at'));
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn(['kind', 'provinces', 'budget', 'limit_period', 'signup_grant', 'claim_valid_days']));
    }
};
