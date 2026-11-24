<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pesan otomatis ke pelanggan untuk pembatalan dan refund.
 * - `orders.locale`: bahasa toko saat checkout (id atau en); pesanan lama memakai id.
 * - `customer_notifications`: satu baris per kejadian dan saluran. Kunci unik mencegah pesan ganda (klik ganda, coba ulang).
 *   Menyimpan kunci kejadian, kunci teks, nomor pengajuan, dan status pengiriman, tanpa email, nomor HP, atau isi pesan.
 * - Saklar WhatsApp pelanggan, mati bawaan sampai akun Meta aktif; nama template diisi Super Admin, tidak di kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('locale', 2)->default('id')->after('customer_phone');
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('channel', 20);
            // Kunci teks di lang/*/customer_mail.php dan nomor pengajuan, supaya pesan bisa dikirim ulang oleh CS.
            $table->string('message', 60);
            $table->string('reference', 40)->nullable();
            // pending, queued, sent, failed, skipped. `error` hanya nama kelas galat, tanpa pesan (bisa memuat alamat atau nomor).
            $table->string('status', 20);
            $table->string('error', 60)->nullable();
            $table->timestamps();

            $table->unique(['event_key', 'channel']);
        });

        DB::table('settings')->insertOrIgnore([
            ['key' => 'notifications.customer_whatsapp_enabled', 'value' => '0', 'type' => 'int', 'group' => 'notifications', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'notifications.customer_whatsapp_template', 'value' => null, 'type' => 'string', 'group' => 'notifications', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['notifications.customer_whatsapp_enabled', 'notifications.customer_whatsapp_template'])->delete();
        Schema::dropIfExists('customer_notifications');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
