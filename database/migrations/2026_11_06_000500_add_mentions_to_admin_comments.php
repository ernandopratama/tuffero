<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda @ pada catatan internal: daftar id staf yang disebut. Hanya disimpan dan ditandai di tampilan;
 * belum ada pemberitahuan ke orang yang ditandai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_comments', function (Blueprint $table) {
            $table->json('mentions')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('admin_comments', function (Blueprint $table) {
            $table->dropColumn('mentions');
        });
    }
};
