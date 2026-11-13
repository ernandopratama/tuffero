<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Draf konten AI Marketing dengan alur persetujuan (D-062, D-079, BR-19). Publikasi tetap manual; tabel hanya menyimpan draf dan statusnya. */
    public function up(): void
    {
        Schema::create('marketing_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('platform', 20)->nullable();
            $table->string('title', 150);
            $table->text('body');
            $table->text('sources')->nullable();
            $table->string('source_period', 100)->nullable();
            $table->date('scheduled_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_drafts');
    }
};
