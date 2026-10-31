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
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 20);
            $table->jsonb('filters')->default('{}');
            $table->string('filters_hash', 64);
            $table->string('status', 10)->default('queued');
            $table->char('requested_by', 26);
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedInteger('row_count')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->timestampsTz();
            $table->index(['requested_by', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_status_check CHECK (status IN ('queued','running','ready','failed','expired'))");
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_type_check CHECK (type IN ('orders','order_items','payments','stock','custom_orders'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
