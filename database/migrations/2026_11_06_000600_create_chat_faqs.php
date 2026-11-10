<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FAQ dan kebijakan toko untuk chat (D-060, sumber pengetahuan kedua). Dikelola admin dari dashboard;
 * tidak ada hapus, FAQ yang tak dipakai dinonaktifkan. Perubahan dicatat di audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('keywords', 500);
            $table->text('answer_id');
            $table->text('answer_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_faqs');
    }
};
