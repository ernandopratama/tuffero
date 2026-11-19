<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor draf yang tampil di dashboard ("Draf #12"). ID publik (ULID, D-143) tidak enak dibaca dan ID angka berbeda antar
 * database, sedangkan nomor ini bisa dibuat sama di lokal dan dev (ContentDraftSeeder). Boleh kosong dan unik: draf yang
 * sudah dihapus boleh melepas nomornya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->unique()->after('id');
        });

        DB::table('marketing_drafts')->update(['number' => DB::raw('id')]);
    }

    public function down(): void
    {
        Schema::table('marketing_drafts', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
