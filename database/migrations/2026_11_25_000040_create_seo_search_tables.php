<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data Google untuk dashboard SEO Fase 2 (D-152, docs/seo/SEO_DASHBOARD_PHASE2.md). Aditif, tanpa data pribadi:
     * angka agregat Search Console dan hasil PageSpeed. Penarikan memakai upsert pada kunci unik; baris lebih tua dari
     * 16 bulan dibersihkan oleh SeoSyncService. Tanggal Search Console mengikuti zona waktu Pasifik dan disimpan apa adanya.
     */
    public function up(): void
    {
        Schema::create('seo_search_daily', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('position', 8, 3)->default(0);
            $table->timestamps();
        });

        foreach (['seo_search_pages' => 'page', 'seo_search_queries' => 'query'] as $name => $key) {
            Schema::create($name, function (Blueprint $table) use ($key, $name) {
                $table->id();
                $table->date('date');
                $table->string($key, 255);
                $table->unsignedInteger('clicks')->default(0);
                $table->unsignedInteger('impressions')->default(0);
                $table->decimal('ctr', 8, 6)->default(0);
                $table->decimal('position', 8, 3)->default(0);
                $table->timestamps();

                $table->unique(['date', $key], $name.'_date_key_unique');
            });
        }

        Schema::create('seo_pagespeed_runs', function (Blueprint $table) {
            $table->id();
            $table->string('url', 255);
            $table->string('strategy', 10)->default('mobile');
            $table->timestamp('ran_at');
            // Nilai lab (Lighthouse).
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedInteger('lcp_ms')->nullable();
            $table->decimal('cls', 6, 3)->nullable();
            $table->unsignedInteger('tbt_ms')->nullable();
            // Data pengguna nyata (CrUX lewat PageSpeed); kosong bila halaman belum punya cukup data.
            $table->unsignedInteger('field_lcp_ms')->nullable();
            $table->unsignedInteger('field_inp_ms')->nullable();
            $table->decimal('field_cls', 6, 3)->nullable();
            $table->string('field_category', 20)->nullable();
            $table->string('field_scope', 10)->nullable();
            $table->timestamps();

            $table->index(['url', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pagespeed_runs');
        Schema::dropIfExists('seo_search_queries');
        Schema::dropIfExists('seo_search_pages');
        Schema::dropIfExists('seo_search_daily');
    }
};
