<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nomor WhatsApp Super Admin untuk halaman Lupa kata sandi pindah dari kode ke Pengaturan. Seeder memakai firstOrCreate,
 * jadi baris ini ditambahkan juga lewat migrasi untuk dev dan production yang sudah berjalan. Nilai awal = nomor yang dulu tertanam di kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'security.super_admin_whatsapp',
            'value' => '+6285117452317',
            'type' => 'string',
            'group' => 'security',
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'security.super_admin_whatsapp')->delete();
    }
};
