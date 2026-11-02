<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Stable name of the page for the code and the back office ("privacy", "terms"); the visible address lives in the translations.
            $table->string('key', 60);
            $table->string('status', 12)->default('draft');
            // Stores (market codes) the page is shown in. Empty = every store (a legal text of Mexico must not show in the US shop).
            $table->jsonb('markets')->default('[]');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUlid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft','published'))");
        DB::statement('CREATE UNIQUE INDEX pages_key_unique ON pages (key) WHERE deleted_at IS NULL');

        Schema::create('page_translations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('slug', 80);
            $table->string('title', 160);
            // The text as written (Markdown) and the safe HTML made from it once, at save time: reading never renders anything.
            $table->text('body_md');
            $table->text('body_html');
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX page_translations_page_locale_unique ON page_translations (page_id, locale) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX page_translations_slug_unique ON page_translations (locale, slug) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('page_translations');
        Schema::dropIfExists('pages');
    }
};
