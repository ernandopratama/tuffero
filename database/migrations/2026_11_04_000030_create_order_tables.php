<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_ref')->unique();
            $table->string('order_number', 20)->unique();
            $table->char('access_token_hash', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_email', 190)->index();
            $table->string('customer_phone', 20)->index();
            $table->json('shipping_address');
            $table->string('shipping_courier', 30);
            $table->string('shipping_service', 50);
            $table->string('status', 30);
            $table->string('payment_status', 30);
            $table->string('shipping_status', 30);
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('discount_total')->default(0);
            $table->unsignedInteger('shipping_fee');
            $table->unsignedInteger('total');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->unique(['id', 'warehouse_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_snapshot', 64);
            $table->string('product_name_snapshot', 150);
            $table->string('variant_name_snapshot', 100);
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('weight_gram_snapshot');
            $table->unsignedInteger('line_total');
            $table->timestamps();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedInteger('quantity');
            $table->string('status', 20);
            $table->dateTime('expires_at');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            // Database menolak reservasi dari gudang yang berbeda dengan gudang order (BR-06).
            $table->foreign(['order_id', 'warehouse_id'])->references(['id', 'warehouse_id'])->on('orders')->restrictOnDelete();
            $table->foreign(['warehouse_id', 'product_variant_id'])->references(['warehouse_id', 'product_variant_id'])->on('inventory_stocks')->restrictOnDelete();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // FK ke refunds ditambahkan bersama tabel refunds.
            $table->unsignedBigInteger('refund_id')->nullable();
            $table->string('status_type', 20);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('source', 30);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_valid CHECK (discount_total <= subtotal)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_valid CHECK (total = subtotal - discount_total + shipping_fee)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_valid CHECK (quantity > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_total_valid CHECK (line_total = unit_price * quantity)');
            DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_valid CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
