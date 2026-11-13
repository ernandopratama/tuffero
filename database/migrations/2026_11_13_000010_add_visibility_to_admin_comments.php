<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tingkat keterlihatan catatan internal: `staff` (semua staf yang boleh melihat datanya, perilaku lama)
 * atau `cs` (hanya yang punya izin `comments.cs`: Customer Service dan Super Admin). Data lama tetap `staff`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_comments', function (Blueprint $table) {
            $table->string('visibility', 10)->default('staff')->after('body');
            $table->index(['commentable_type', 'commentable_id', 'visibility'], 'admin_comments_target_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('admin_comments', function (Blueprint $table) {
            $table->dropIndex('admin_comments_target_visibility');
            $table->dropColumn('visibility');
        });
    }
};
