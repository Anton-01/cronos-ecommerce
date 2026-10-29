<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 64); // technical key, e.g. "color", "width"
            $table->string('type', 16)->default('select');
            $table->boolean('is_filterable')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement("ALTER TABLE attributes ADD CONSTRAINT attributes_type_check CHECK (type IN ('select','color','text','number'))");
        DB::statement('CREATE UNIQUE INDEX attributes_code_unique ON attributes (code) WHERE deleted_at IS NULL');

        Schema::create('attribute_translations', function (Blueprint $table) {
            $table->foreignUlid('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->timestampsTz();
            $table->primary(['attribute_id', 'locale']);
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('attribute_id')->constrained('attributes')->restrictOnDelete();
            $table->string('code', 64);
            $table->jsonb('meta')->nullable(); // e.g. {"hex": "#C8285A"}
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX attribute_values_code_unique ON attribute_values (attribute_id, code) WHERE deleted_at IS NULL');

        Schema::create('attribute_value_translations', function (Blueprint $table) {
            $table->foreignUlid('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label');
            $table->timestampsTz();
            $table->primary(['attribute_value_id', 'locale']);
        });
    }

    public function down(): void
    {
        foreach (['attribute_value_translations', 'attribute_values', 'attribute_translations', 'attributes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
