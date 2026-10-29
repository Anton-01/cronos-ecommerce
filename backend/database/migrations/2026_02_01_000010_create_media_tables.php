<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Polymorphic owner. Both null = file lives in the library, not attached yet.
            $table->string('model_type')->nullable();
            $table->char('model_id', 26)->nullable();
            $table->string('collection_name', 64)->default('default');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type', 127);
            $table->string('disk', 32);
            $table->unsignedBigInteger('size');
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration', 10, 3)->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->jsonb('custom_properties')->default('{}');
            $table->jsonb('generated_conversions')->default('{}');
            $table->jsonb('responsive_images')->default('{}');
            $table->unsignedInteger('order_column')->nullable();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['model_type', 'model_id', 'collection_name']);
            $table->index('checksum_sha256');
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_status_check CHECK (status IN ('pending','processing','ready','failed'))");
        DB::statement('CREATE INDEX media_name_trgm ON media USING gin (name gin_trgm_ops)');

        Schema::create('media_translations', function (Blueprint $table) {
            $table->foreignUlid('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->timestampsTz();
            $table->primary(['media_id', 'locale']);
        });

        // The avatar now points at the Media Library instead of a bare path.
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->foreignUlid('avatar_media_id')->nullable()->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_media_id');
            $table->string('avatar_path')->nullable();
        });
        Schema::dropIfExists('media_translations');
        Schema::dropIfExists('media');
    }
};
