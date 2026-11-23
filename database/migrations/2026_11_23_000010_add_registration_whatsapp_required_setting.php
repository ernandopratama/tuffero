<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Saklar pendaftaran pelanggan (D-146). Mati (bawaan): cukup verifikasi email, nomor WhatsApp tidak diminta.
 * Nyala: email dan WhatsApp keduanya wajib (D-145). Seeder memakai firstOrCreate, jadi baris ini juga ditambahkan lewat migrasi
 * untuk dev dan production yang sudah berjalan. insertOrIgnore menjaga nilai yang sudah diatur Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'registration.whatsapp_required',
            'value' => '0',
            'type' => 'int',
            'group' => 'registration',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'registration.whatsapp_required')->delete();
    }
};
