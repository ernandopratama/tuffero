<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori dan jenis produk dikelola dari dashboard (Super Admin dan Admin Toko). Urutan: Kategori, Jenis, Produk, Varian.
 * Migration ini juga mengisi tujuh kategori awal dan memasukkan 29 SKU yang sudah ada ke kategorinya (sebelumnya di config/store_catalog.php).
 */
return new class extends Migration
{
    /** @var list<array{string, string, string}> slug, nama Indonesia, nama Inggris */
    private const CATEGORIES = [
        ['nastar', 'Nastar', 'Nastar'],
        ['macaron', 'Macaron', 'Macarons'],
        ['pia', 'Pia', 'Pia'],
        ['cookies', 'Cookies', 'Cookies'],
        ['brownies-cake', 'Brownies & Cake', 'Brownies & Cakes'],
        ['hampers', 'Hampers', 'Hampers'],
        ['lainnya', 'Produk Lainnya', 'Other Products'],
    ];

    /** @var list<array{string, string, string, string}> slug jenis, slug kategori, nama Indonesia, nama Inggris */
    private const TYPES = [
        ['sultan', 'nastar', 'Sultan', 'Sultan'],
        ['classic', 'nastar', 'Classic', 'Classic'],
        ['apel', 'nastar', 'Apel', 'Apple'],
        ['nastar-cookies', 'nastar', 'Cookies', 'Cookies'],
        ['nastar-paket', 'nastar', 'Paket', 'Set'],
        ['mini', 'macaron', 'Mini', 'Mini'],
        ['regular', 'macaron', 'Regular', 'Regular'],
        ['crumbs', 'macaron', 'Crumbs', 'Crumbs'],
        ['rice', 'macaron', 'Rice Crispy', 'Rice Crispy'],
        ['macaron-paket', 'macaron', 'Paket', 'Set'],
        ['brownies', 'brownies-cake', 'Brownies', 'Brownies'],
        ['dubai', 'brownies-cake', 'Cokelat Dubai', 'Dubai Chocolate'],
        ['selai', 'lainnya', 'Selai', 'Jam'],
        ['terasi', 'lainnya', 'Terasi', 'Terasi'],
        ['sambal', 'lainnya', 'Sambal', 'Sambal'],
        ['lainnya-paket', 'lainnya', 'Paket', 'Set'],
    ];

    /** @var array<string, array{string, string|null}> SKU awal: kategori dan jenis */
    private const SKUS = [
        'NAS-TOP-01' => ['nastar', 'sultan'], 'NAS-CLAS-01' => ['nastar', 'classic'], 'NAS-APEL-01' => ['nastar', 'apel'],
        'NAS-COO-01' => ['nastar', 'nastar-cookies'], 'BUNDLING-NAS-02' => ['nastar', 'nastar-paket'],
        'MAC-MIN-30' => ['macaron', 'mini'], 'MAC-MIN-31' => ['macaron', 'mini'], 'MAC-MIN-50' => ['macaron', 'mini'],
        'MAC-REG-06' => ['macaron', 'regular'], 'MAC-REM-01' => ['macaron', 'crumbs'],
        'MAC-RICE-01' => ['macaron', 'rice'], 'MAC-RICE-02' => ['macaron', 'rice'], 'MAC-PAK-01' => ['macaron', 'macaron-paket'],
        'PIA-OLH-02' => ['pia', null],
        'COO-BIT-02' => ['cookies', null], 'COO-LUM-01' => ['cookies', null], 'MER-TOP-01' => ['cookies', null],
        'BRW-BOX-03' => ['brownies-cake', 'brownies'], 'CHO-DUB-01' => ['brownies-cake', 'dubai'], 'CHO-DUB-02' => ['brownies-cake', 'dubai'],
        'SELAI-NANAS-01' => ['lainnya', 'selai'],
        'TERASI-KING-01' => ['lainnya', 'terasi'], 'TERASI-KING-02' => ['lainnya', 'terasi'], 'TERASI-KING-03' => ['lainnya', 'terasi'], 'TERASI-SACHET-12' => ['lainnya', 'terasi'],
        'BUMBU-HTM-01' => ['lainnya', 'sambal'], 'BUMBU-HTM-03' => ['lainnya', 'sambal'], 'BUMBU-HTM-05' => ['lainnya', 'sambal'],
        'BUMBU-HTM-02' => ['lainnya', 'lainnya-paket'],
    ];

    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name_id', 80);
            $table->string('name_en', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('slug', 60)->unique();
            $table->string('name_id', 80);
            $table->string('name_en', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_category_id')->nullable()->after('slug')->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('product_type_id')->nullable()->after('product_category_id')->constrained('product_types')->restrictOnDelete();
        });

        $now = now();
        foreach (self::CATEGORIES as $order => [$slug, $nameId, $nameEn]) {
            DB::table('product_categories')->insert(['slug' => $slug, 'name_id' => $nameId, 'name_en' => $nameEn, 'sort_order' => $order + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $categoryIds = DB::table('product_categories')->pluck('id', 'slug');

        foreach (self::TYPES as $order => [$slug, $categorySlug, $nameId, $nameEn]) {
            DB::table('product_types')->insert(['product_category_id' => $categoryIds[$categorySlug], 'slug' => $slug, 'name_id' => $nameId, 'name_en' => $nameEn, 'sort_order' => $order + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $typeIds = DB::table('product_types')->pluck('id', 'slug');

        // Hanya produk yang belum punya kategori, jadi aman dijalankan ulang.
        foreach (self::SKUS as $sku => [$categorySlug, $typeSlug]) {
            $productId = DB::table('product_variants')->where('sku', $sku)->value('product_id');
            if ($productId) {
                DB::table('products')->where('id', $productId)->whereNull('product_category_id')->update([
                    'product_category_id' => $categoryIds[$categorySlug],
                    'product_type_id' => $typeSlug ? $typeIds[$typeSlug] : null,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_type_id');
            $table->dropConstrainedForeignId('product_category_id');
        });
        Schema::dropIfExists('product_types');
        Schema::dropIfExists('product_categories');
    }
};
