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
        Schema::create('newsletter_campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 160); // internal name, never shown to subscribers
            $table->string('status', 12)->default('draft');
            // Who gets it: confirmed subscribers only, optionally narrowed to some stores and/or languages. Empty list = no narrowing.
            $table->jsonb('audience')->default('{"markets":[],"locales":[]}');
            // { "es-MX": { "subject": .., "preheader": .., "body_md": .. }, "en-US": { .. } }; a subscriber gets their own language, else the first written.
            $table->jsonb('translations')->default('{}');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->foreignUlid('created_by')->nullable();
            $table->foreignUlid('updated_by')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'scheduled_at']);
        });
        DB::statement("ALTER TABLE newsletter_campaigns ADD CONSTRAINT newsletter_campaigns_status_check CHECK (status IN ('draft','scheduled','sending','sent','cancelled'))");

        Schema::create('newsletter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('campaign_id')->constrained('newsletter_campaigns')->cascadeOnDelete();
            $table->char('subscriber_id', 26);
            $table->string('email', 190);
            $table->string('locale', 8);
            $table->unsignedInteger('batch');
            // queued → sent (accepted by Resend) → delivered | bounced | complained (webhooks); failed = Resend refused it
            $table->string('status', 12)->default('queued');
            $table->string('provider_id', 64)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            // The snapshot of recipients is taken once: a second "send" of the same campaign cannot mail anybody twice.
            $table->unique(['campaign_id', 'subscriber_id']);
            $table->index(['campaign_id', 'status']);
            $table->index('provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_deliveries');
        Schema::dropIfExists('newsletter_campaigns');
    }
};
