<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan refund dan retur barang (D-031, D-043, D-097). Rancangan di docs/database/DATABASE.md bagian Refund.
 * Nomor rekening pelanggan tidak disimpan; video unboxing tidak diunggah ke situs (hanya status penerimaannya yang dicatat).
 * Kolom waktu memakai DATETIME (bukan TIMESTAMP) supaya aman di MariaDB 10.6 pada server dev.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->char('public_ref', 36)->unique();
            $table->string('refund_number', 20)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('requester_email', 190)->index();
            $table->string('requester_phone', 20)->nullable()->index();
            $table->string('contact_preference', 20);
            $table->string('reason_type', 30);
            // Pilihan pelanggan setelah video lengkap (dicatat CS): dana kembali (bawaan), kirim ulang, atau voucher pengganti.
            $table->string('customer_choice', 20)->default('refund');
            $table->text('description');
            $table->unsignedInteger('requested_amount');
            $table->unsignedInteger('approved_amount')->nullable();
            // Ongkir yang ikut dikembalikan, hanya bila kesalahan ada di Tuffero (salah kirim atau barang kurang) dan dipilih Pemilik.
            $table->unsignedInteger('shipping_amount')->default(0);
            $table->string('evidence_status', 20)->default('awaiting');
            $table->string('evidence_channel', 20)->nullable();
            $table->dateTime('evidence_received_at')->nullable();
            $table->string('evidence_note', 255)->nullable();
            $table->string('terms_version', 20);
            $table->dateTime('terms_accepted_at');
            $table->string('status', 30);
            $table->dateTime('due_at');
            $table->dateTime('contacted_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->string('refund_method', 30)->nullable();
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('refunded_at')->nullable();
            $table->string('refund_reference', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_snapshot');
            $table->unsignedInteger('line_amount');
            $table->timestamps();

            $table->unique(['refund_id', 'order_item_id']);
        });

        // Batas retur produk utuh (D-097). insertOrIgnore: aman diulang, dan server dev tidak perlu menjalankan seeder.
        DB::table('settings')->insertOrIgnore([
            'key' => 'refund.return_window_days', 'value' => '3', 'type' => 'int', 'group' => 'refund', 'is_public' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_requested_positive CHECK (requested_amount > 0)');
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_approved_within_requested CHECK (approved_amount IS NULL OR approved_amount <= requested_amount)');
            DB::statement('ALTER TABLE refund_items ADD CONSTRAINT refund_items_quantity_positive CHECK (quantity > 0)');
            // line_amount = yang dibayar pelanggan untuk barang itu (setelah bagian potongan voucher), jadi tidak melebihi harga satuan x jumlah.
            DB::statement('ALTER TABLE refund_items ADD CONSTRAINT refund_items_line_amount_valid CHECK (line_amount > 0 AND line_amount <= unit_price_snapshot * quantity)');
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'refund.return_window_days')->delete();
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
    }
};
