<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-072: klaim voucher ke "Voucher Saya" seperti Shopee. Hanya voucher yang ditandai perlu klaim yang butuh akun.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->boolean('requires_claim')->default(false)->after('is_listed');
        });

        Schema::create('voucher_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('claimed_at')->useCurrent();

            $table->unique(['voucher_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_claims');
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn('requires_claim'));
    }
};
