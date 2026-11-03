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
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email', 190);
            // pending = asked, not yet confirmed (nothing may be sent but the confirmation) | confirmed | unsubscribed
            $table->string('status', 12)->default('pending');
            $table->string('locale', 8);
            $table->string('market_code', 8)->nullable();
            $table->string('source', 16); // footer | register | admin
            $table->foreignUlid('user_id')->nullable();
            // Proof of consent: when, from where and how it was given (double opt-in: asked + confirmed).
            $table->timestampTz('consented_at');
            $table->string('consent_ip', 45)->nullable();
            $table->char('consent_country', 2)->nullable();
            $table->string('consent_user_agent', 255)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('confirmed_ip', 45)->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->string('unsubscribed_by', 12)->nullable(); // subscriber | admin
            $table->timestampTz('confirmation_sent_at')->nullable();
            $table->unsignedSmallInteger('confirmation_count')->default(0);
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_status_check CHECK (status IN ('pending','confirmed','unsubscribed'))");
        // One row per address, whatever the capitals: the second sign-up is the same person.
        DB::statement('CREATE UNIQUE INDEX newsletter_email_unique ON newsletter_subscribers (lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
