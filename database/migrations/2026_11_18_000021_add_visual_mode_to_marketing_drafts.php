<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sumber visual draf video (D-140): `team` (foto/rekaman tim, bawaan) atau `ai` (dibuat AI sepenuhnya dari minimal 2 foto acuan). Aditif. */
    public function up(): void
    {
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->string('visual_mode', 20)->default('team')->after('music_note');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->dropColumn('visual_mode');
        });
    }
};
