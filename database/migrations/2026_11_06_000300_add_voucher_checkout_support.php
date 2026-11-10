<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-069: voucher dipakai di checkout. Segmen Non Member / Member (D-066), daftar pilihan ala Shopee, satu pakai per pelanggan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('description', 150)->nullable()->after('code');
            // all = semua pelanggan, guest = Non Member (tanpa login), member = pelanggan yang login.
            $table->string('audience', 10)->default('all')->after('type');
            $table->boolean('is_listed')->default(true)->after('is_active');
            $table->unsignedSmallInteger('per_customer_limit')->nullable()->default(1)->after('quota');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('voucher_code', 40)->nullable()->after('discount_total');
        });

        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_phone', 20)->index();
            $table->unsignedInteger('amount');
            // Terisi saat pesanan batal atau kedaluwarsa: kuota kembali dan pemakaian tidak lagi dihitung.
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->unique(['voucher_id', 'order_id']);
            $table->index(['voucher_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('voucher_code'));
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn(['description', 'audience', 'is_listed', 'per_customer_limit']));
    }
};
