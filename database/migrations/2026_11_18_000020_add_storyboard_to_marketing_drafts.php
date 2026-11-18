<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Naskah dan storyboard otomatis untuk draf skrip video (D-127 diperbarui 18 Nov 2026). Aditif: kolom baru nullable
     * dan dua tabel anak. Foto acuan disimpan di disk privat; tabel hanya menyimpan path-nya.
     */
    public function up(): void
    {
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_seconds')->nullable()->after('scheduled_on');
            $table->string('music_note', 300)->nullable()->after('duration_seconds');
            $table->json('storyboard')->nullable()->after('music_note');
            $table->string('storyboard_status', 20)->nullable()->after('storyboard');
            $table->string('storyboard_error', 300)->nullable()->after('storyboard_status');
            $table->dateTime('storyboard_at')->nullable()->after('storyboard_error');
        });

        Schema::create('marketing_draft_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_draft_id')->constrained()->cascadeOnDelete();
            $table->string('path', 200);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_draft_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('body', 1000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_draft_notes');
        Schema::dropIfExists('marketing_draft_references');
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->dropColumn(['duration_seconds', 'music_note', 'storyboard', 'storyboard_status', 'storyboard_error', 'storyboard_at']);
        });
    }
};
