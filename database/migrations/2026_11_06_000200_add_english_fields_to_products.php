<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// D-065: terjemahan Inggris tersimpan di database. Mode 'ai' = diterjemahkan otomatis saat disimpan; 'manual' = admin yang mengisi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name_en', 150)->nullable()->after('name');
            $table->text('description_en')->nullable()->after('description');
            $table->string('translation_mode', 10)->default('ai')->after('description_en');
            // Sidik teks Indonesia saat terjemahan terakhir dibuat; beda dengan sidik sekarang = terjemahan usang.
            $table->string('translation_hash', 40)->nullable()->after('translation_mode');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('name_en', 100)->nullable()->after('name');
        });

        // Nama Inggris tulisan tangan dari config/store_catalog.php dipindah ke database dan dikunci sebagai manual.
        foreach (config('store_catalog.meta', []) as $sku => $meta) {
            $productId = DB::table('product_variants')->where('sku', $sku)->value('product_id');
            if ($productId && ! empty($meta['en'])) {
                DB::table('products')->where('id', $productId)->update(['name_en' => $meta['en'], 'translation_mode' => 'manual']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('name_en'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['name_en', 'description_en', 'translation_mode', 'translation_hash']));
    }
};
