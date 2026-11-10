<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto galeri toko yang dikelola dari dashboard (D-083). Tabel baru; tidak mengubah data lain.
 * Foto bawaan halaman Galeri dimasukkan sebagai baris awal supaya tampilan toko tidak berubah.
 * Jalur `images/...` = berkas statis di public/; jalur lain = unggahan di disk `public` (storage/app/public).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('title_en', 150)->nullable();
            $table->string('category', 20);
            $table->string('image_path');
            $table->string('full_path')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_published', 'sort_order'], 'gallery_photos_live_index');
        });

        $labels = [
            'sultan' => ['Nastar Sultan', 'Sultan Nastar'], 'apel' => ['Nastar Apel', 'Apple Nastar'], 'classic' => ['Nastar Classic', 'Classic Nastar'],
            'nastarCookies' => ['Nastar Cookies', 'Nastar Cookies'], 'selai' => ['Selai Nanas Nastar Sultan', 'Sultan Nastar Pineapple Jam'],
            'mini' => ['Macaron Mini', 'Mini Macarons'], 'regular' => ['Macaron Regular', 'Regular Macarons'], 'crumbs' => ['Macaron Crumbs', 'Macaron Crumbs'],
            'rice' => ['Macaron Rice Crispy', 'Macaron Rice Crispy'], 'pia' => ['Pia Cokelat Premium', 'Premium Chocolate Pia'], 'dubai' => ['Cokelat Dubai', 'Dubai Chocolate'],
            'brownies' => ['Brownies Marble', 'Marble Brownies'], 'meringue' => ['Meringue Cookies', 'Meringue Cookies'], 'cookies' => ['Choco Chips Cookies', 'Choco Chips Cookies'],
        ];

        // [berkas di public/images/Produk, kategori, kunci label, foto besar?] dalam urutan tampil lama.
        $photos = [
            ['Nastar Sultan 3', 'nastar', 'sultan', true], ['Macaron isi 50 3', 'macaron', 'mini'], ['Pia premium 3', 'pia', 'pia'], ['Dubai 3', 'snack', 'dubai'],
            ['Nastar Apel 4', 'nastar', 'apel'], ['Meringue 3', 'snack', 'meringue'], ['Macaron isi 30 3', 'macaron', 'mini', true], ['Nastar Classic 5', 'nastar', 'classic'],
            ['brownies susu 3', 'snack', 'brownies'], ['Nastar Sultan 2', 'nastar', 'sultan'], ['macaron regular 2', 'macaron', 'regular'], ['Pia premium 2', 'pia', 'pia', true],
            ['Nastar Cookies 3', 'nastar', 'nastarCookies'], ['Cookies 3', 'snack', 'cookies'], ['selai 4', 'nastar', 'selai'], ['Macaron isi 50 1', 'macaron', 'mini'],
            ['Nastar Sultan 7', 'nastar', 'sultan'], ['Meringue 1', 'snack', 'meringue'], ['Nastar Apel 1', 'nastar', 'apel', true], ['Pia premium 1', 'pia', 'pia'],
            ['Dubai 2', 'snack', 'dubai'], ['Macaron New Packing 1', 'macaron', 'mini'], ['brownies susu 4', 'snack', 'brownies'], ['Nastar Classic 3', 'nastar', 'classic'],
            ['rice strawberry 2', 'macaron', 'rice'], ['Nastar Cookies 2', 'nastar', 'nastarCookies'], ['Macaron Crumb 3', 'macaron', 'crumbs'], ['selai 2', 'nastar', 'selai'],
        ];

        $now = now();
        DB::table('gallery_photos')->insert(array_map(fn (array $p, int $i) => [
            'title' => $labels[$p[2]][0],
            'title_en' => $labels[$p[2]][1],
            'category' => $p[1],
            'image_path' => "images/Produk/gallery/{$p[0]}.webp",
            'full_path' => "images/Produk/{$p[0]}_result.webp",
            'is_featured' => $p[3] ?? false,
            'sort_order' => ($i + 1) * 10,
            'is_published' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $photos, array_keys($photos)));
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_photos');
    }
};
