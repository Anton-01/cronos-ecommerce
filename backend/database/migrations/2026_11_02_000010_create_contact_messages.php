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
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('market_code', 8)->nullable();
            $table->string('locale', 8);
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 32)->nullable();
            $table->string('topic', 16);
            $table->string('order_number', 40)->nullable();
            $table->text('message');
            // new → read → replied; "spam" is kept (not deleted) so a wrongly caught person can still be found and answered.
            $table->string('status', 10)->default('new');
            $table->jsonb('spam_signals')->default('[]');
            // passed (Turnstile said yes) | skipped (no key configured) | unavailable (Cloudflare unreachable) | failed (never stored)
            $table->string('human_check', 12);
            $table->foreignUlid('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignUlid('handled_by')->nullable();
            $table->timestampTz('handled_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index(['email', 'created_at']);
        });
        DB::statement("ALTER TABLE contact_messages ADD CONSTRAINT contact_messages_status_check CHECK (status IN ('new','read','replied','spam'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
