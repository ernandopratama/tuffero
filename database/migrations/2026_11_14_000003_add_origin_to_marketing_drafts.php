<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asal draf: `manual` (diketik staf), `ai` (pembuat Claude di dashboard), `hermes` (laporan riset terjadwal dari Hermes, D-079).
     * `external_id` membuat kiriman Hermes idempoten: kiriman ulang untuk laporan yang sama tidak membuat draf kedua.
     */
    public function up(): void
    {
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->string('origin', 20)->default('manual')->after('status');
            $table->string('external_id', 100)->nullable()->unique()->after('origin');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['origin', 'external_id']);
        });
    }
};
