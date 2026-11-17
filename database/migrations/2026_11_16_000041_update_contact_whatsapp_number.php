<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-128 (14 Nov 2026): nomor WhatsApp CS menjadi 0812-5250-7880, menggantikan +6281231481755 (D-054). Seeder memakai firstOrCreate,
 * jadi baris yang sudah ada di dev atau production hanya berubah lewat migrasi ini. Nilai yang sudah diubah Super Admin atau
 * Admin Toko ke nomor lain tidak ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'contact.whatsapp_number')->where('value', '+6281231481755')->update(['value' => '+6281252507880', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'contact.whatsapp_number')->where('value', '+6281252507880')->update(['value' => '+6281231481755', 'updated_at' => now()]);
    }
};
