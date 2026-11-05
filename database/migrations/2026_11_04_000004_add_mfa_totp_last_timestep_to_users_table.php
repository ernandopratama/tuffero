<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Kode TOTP sekali pakai: kode dari langkah waktu yang sudah dipakai ditolak.
            $table->unsignedBigInteger('mfa_totp_last_timestep')->nullable()->after('mfa_totp_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mfa_totp_last_timestep');
        });
    }
};
