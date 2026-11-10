<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('city', 80);
            // Alamat gudang Malang belum ditetapkan (DATABASE.md), jadi boleh kosong sampai diisi sebelum integrasi Biteship.
            $table->text('address')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_variant_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_stock_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->integer('quantity_change');
            $table->integer('reserved_change')->default(0);
            $table->integer('quantity_after');
            $table->integer('reserved_after');
            // FK ke orders ditambahkan bersama tabel orders (urutan migration di DATABASE.md).
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['inventory_stock_id', 'created_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_quantity_valid CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_reserved_valid CHECK (reserved >= 0 AND reserved <= quantity)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('warehouses');
    }
};
