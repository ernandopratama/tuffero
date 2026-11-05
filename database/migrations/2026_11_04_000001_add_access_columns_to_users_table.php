<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('mfa_required')->default(true)->after('is_active');
            $table->text('mfa_totp_secret')->nullable()->after('mfa_required');
            $table->timestamp('mfa_totp_confirmed_at')->nullable()->after('mfa_totp_secret');
            $table->boolean('mfa_email_enabled')->default(false)->after('mfa_totp_confirmed_at');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_active',
                'mfa_required',
                'mfa_totp_secret',
                'mfa_totp_confirmed_at',
                'mfa_email_enabled',
                'mfa_recovery_codes',
            ]);
        });
    }
};
