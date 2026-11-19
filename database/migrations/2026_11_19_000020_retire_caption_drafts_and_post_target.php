<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Caption dan kalender konten tidak lagi menjadi draf: caption pindah ke Generator Caption, kalender konten digabung ke
 * skrip video (rencana video). Draf lama berjenis itu dihapus ringan (bisa dipulihkan dari database) dan nomornya dilepas;
 * target "posting per minggu" (caption dan kalender) ikut dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('marketing_drafts')
            ->whereIn('type', ['caption', 'calendar_item'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'number' => null]);

        DB::table('settings')->where('key', 'marketing.posts_per_week')->delete();
    }

    public function down(): void
    {
        // Data lama dihapus ringan dan tidak dipulihkan otomatis.
    }
};
