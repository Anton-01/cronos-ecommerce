<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only overrides are stored here. The catalogue of valid keys (type, visibility,
     * translatable or not, defaults) lives in code, so data can never widen what is public.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('group', 64);
            $table->string('key', 128);
            $table->jsonb('value')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['group', 'key']);
        });

        Schema::create('setting_translations', function (Blueprint $table) {
            $table->foreignUlid('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->text('value')->nullable();
            $table->timestampsTz();
            $table->primary(['setting_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_translations');
        Schema::dropIfExists('settings');
    }
};
