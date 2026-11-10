<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 20);
            $table->unsignedInteger('value');
            $table->unsignedInteger('min_subtotal')->default(0);
            $table->unsignedInteger('max_discount')->nullable();
            $table->unsignedInteger('quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('promo_contents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('video_url', 255)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('admin_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->morphs('commentable');
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_quota_valid CHECK (quota IS NULL OR used_count <= quota)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_comments');
        Schema::dropIfExists('promo_contents');
        Schema::dropIfExists('vouchers');
    }
};
