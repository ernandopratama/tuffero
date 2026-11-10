<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('tracking_id', 64)->nullable()->after('provider_order_id');
        });

        // Riwayat pelacakan per kejadian. Unik per (shipment, event_key) supaya sinkronisasi ulang tidak menggandakan baris.
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->string('status', 30);
            $table->string('note', 300);
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->unique(['shipment_id', 'event_key']);
            $table->index(['shipment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('tracking_id'));
    }
};
