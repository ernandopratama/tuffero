<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Preferensi notifikasi staf (jenis yang dimatikan) dan perangkat yang pernah dipakai masuk (peringatan perangkat baru, D-090). */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_muted')->nullable()->after('avatar_path');
        });

        Schema::create('login_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('browser', 40);
            $table->string('os', 40);
            $table->string('ip_address', 45)->nullable();
            // useCurrent: MariaDB (server dev) menolak TIMESTAMP NOT NULL kedua tanpa default, dan memberi kolom pertama ON UPDATE otomatis.
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->unique(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_devices');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_muted');
        });
    }
};
