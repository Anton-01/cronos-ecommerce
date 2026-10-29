<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable();
            $table->unsignedSmallInteger('depth')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['parent_id', 'position']);
        });
        Schema::table('categories', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete());
        // Materialised path of ULID labels ("rootid.childid"): subtree queries become one indexed lookup.
        DB::statement('ALTER TABLE categories ADD COLUMN path ltree NOT NULL');
        DB::statement('CREATE INDEX categories_path_gist ON categories USING gist (path)');

        Schema::create('category_translations', function (Blueprint $table) {
            $table->foreignUlid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            // Mirrors the parent's soft delete so a deleted category frees its slug.
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->primary(['category_id', 'locale']);
        });
        DB::statement('CREATE UNIQUE INDEX category_translations_slug_unique ON category_translations (locale, slug) WHERE deleted_at IS NULL');

        Schema::create('tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('tag_translations', function (Blueprint $table) {
            $table->foreignUlid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->string('slug');
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->primary(['tag_id', 'locale']);
        });
        DB::statement('CREATE UNIQUE INDEX tag_translations_slug_unique ON tag_translations (locale, slug) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        foreach (['tag_translations', 'tags', 'category_translations', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
