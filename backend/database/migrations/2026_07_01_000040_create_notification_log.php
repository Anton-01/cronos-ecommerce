<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One row per message the shop decided to send: the unique (template, dedupe_key) is what stops an event replay from mailing twice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('template', 40);
            $table->string('dedupe_key', 120);
            $table->char('user_id', 26)->nullable();
            $table->string('email', 255);
            $table->string('locale', 10);
            $table->string('related_type', 24)->nullable();
            $table->string('related_id', 26)->nullable();
            $table->jsonb('data');
            $table->string('status', 10)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['template', 'dedupe_key']);
            $table->index(['related_type', 'related_id']);
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE notification_log ADD CONSTRAINT notification_log_status_check CHECK (status IN ('queued','sent','failed','skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
