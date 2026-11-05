<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_order_id', 64)->nullable()->unique();
            $table->string('courier_code', 30);
            $table->string('service_code', 50);
            $table->string('waybill_id', 64)->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('shipping_fee');
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('request_number', 20)->unique();
            $table->string('reason', 500);
            $table->string('channel', 20);
            $table->string('status', 30);
            $table->dateTime('due_at');
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->foreignId('store_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('store_approved_at')->nullable();
            $table->foreignId('owner_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('owner_approved_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancellation_requests');
        Schema::dropIfExists('shipments');
    }
};
