<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // D-122: blokir otomatis setelah retur produk utuh tiga pesanan terakhir berturut-turut. Nomor dan alamat dicocokkan lewat
        // hash; nama dan IP hanya bukti. Blokir tidak dihapus, hanya dicabut (riwayat tetap ada).
        Schema::create('customer_blocks', function (Blueprint $table) {
            $table->id();
            $table->char('phone_hash', 64)->index();
            $table->char('address_hash', 64)->nullable()->index();
            $table->string('phone_masked', 20);
            $table->string('customer_name', 120);
            $table->string('address_summary', 190)->nullable();
            $table->string('ip_address', 45)->nullable();
            // auto = dari aturan retur berturut-turut; manual = ditambahkan staf (customer_blocks.manage), `note` wajib.
            $table->string('source', 10)->default('auto');
            $table->string('note', 255)->nullable();
            $table->json('order_ids');
            $table->json('refund_ids');
            // true selama aktif, NULL setelah dicabut: indeks unik di bawah hanya membatasi satu blokir aktif per nomor,
            // dan dua retur yang disetujui bersamaan tidak bisa membuat dua blokir.
            $table->boolean('active_flag')->nullable()->default(true);
            $table->dateTime('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['phone_hash', 'active_flag']);
        });

        // IP pembeli saat checkout: hanya dicatat sebagai bukti (tidak dipakai menolak, lihat D-122 dan DECISION_LOG).
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('shipping_address');
        });

        $now = now();
        foreach ([
            ['antifraud.return_block_enabled', '0', 'int'],
            ['antifraud.return_block_streak', '3', 'int'],
        ] as [$key, $value, $type]) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => $value, 'type' => $type, 'group' => 'antifraud', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['antifraud.return_block_enabled', 'antifraud.return_block_streak'])->delete();
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('ip_address'));
        Schema::dropIfExists('customer_blocks');
    }
};
