<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The whole visual identity of the storefront as versions: colours, type, shapes, logos, menus, home sections and feature switches.
 * One draft is edited at will, one version is live; publishing is a switch, never an edit of the live version, so every change can be
 * previewed first and any earlier look can be brought back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedInteger('number')->unique();
            $table->string('status', 10)->default('draft');
            $table->string('name', 80)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('tokens')->default('{}');
            $table->jsonb('assets')->default('{}');
            $table->jsonb('navigation')->default('{}');
            $table->jsonb('home')->default('[]');
            $table->jsonb('features')->default('{}');
            $table->char('derived_from', 26)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('published_by', 26)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_status_check CHECK (status IN ('draft','published','archived'))");
        DB::statement("CREATE UNIQUE INDEX theme_versions_one_draft ON theme_versions ((status)) WHERE status = 'draft'");
        DB::statement("CREATE UNIQUE INDEX theme_versions_one_published ON theme_versions ((status)) WHERE status = 'published'");
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
    }
};
