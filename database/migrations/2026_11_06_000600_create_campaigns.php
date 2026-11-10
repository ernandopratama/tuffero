<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-073: kampanye promo (tema + periode) yang membungkus diskon produk dan voucher; flash sale = kampanye dengan hitung mundur dan jumlah terjual.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_flash')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'ends_at']);
        });

        // Diskon dan voucher milik kampanye hanya berlaku selama kampanye aktif dan dalam periodenya.
        Schema::table('variant_discounts', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('product_variant_id')->constrained()->restrictOnDelete();
        });
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('requires_claim')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::table('variant_discounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::dropIfExists('campaigns');
    }
};
