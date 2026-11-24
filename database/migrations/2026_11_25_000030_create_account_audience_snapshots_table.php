<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demografi followers (D-151 butir 5): satu snapshot per platform dan bulan, disalin manual dari insight akun.
     * `data` berisi daftar usia, gender, dan lokasi (label dan persen); bentuknya dijaga `SocialAudienceRequest`.
     */
    public function up(): void
    {
        Schema::create('account_audience_snapshots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('platform', 20);
            $table->date('month');
            $table->boolean('is_test')->default(false);
            $table->json('data');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'month', 'is_test'], 'aas_platform_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_audience_snapshots');
    }
};
