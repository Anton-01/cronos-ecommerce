<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per gateway. The credentials of BOTH modes live side by side (encrypted); `mode` says which pair is in use.
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique(); // stripe | mercadopago | paypal
            $table->string('name', 60);
            $table->boolean('is_enabled')->default(false);
            $table->string('mode', 8)->default('sandbox'); // sandbox | live
            $table->text('credentials_sandbox')->nullable();
            $table->text('credentials_live')->nullable();
            $table->jsonb('markets')->default('[]'); // market codes this gateway may charge in; empty = none
            $table->decimal('min_amount', 14, 2)->nullable();
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->timestampTz('live_since')->nullable();
            $table->char('live_enabled_by', 26)->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE payment_providers ADD CONSTRAINT payment_providers_mode_check CHECK (mode IN ('sandbox','live') AND code IN ('stripe','mercadopago','paypal'))");

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 24);
            $table->string('mode', 8); // the mode this payment was created in: it never changes, even if the shop goes live later
            $table->string('payable_type', 24); // checkout now, order from Phase 7
            $table->string('payable_id', 40);
            $table->string('status', 20)->default('pending');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('captured_amount', 14, 2)->default(0);
            $table->decimal('refunded_amount', 14, 2)->default(0);
            $table->string('provider_reference', 120)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('customer_email', 190)->nullable();
            $table->string('description', 200)->nullable();
            $table->text('client_action')->nullable(); // encrypted: what the shopper's device needs to continue (client secret, approval URL)
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->string('anomaly', 40)->nullable(); // needs a human: late_capture, amount_mismatch, duplicate_capture...
            $table->jsonb('metadata')->default('{}');
            $table->ipAddress('ip_address')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestampsTz();

            $table->index(['provider', 'provider_reference']);
            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'updated_at']);
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','requires_action','processing','captured','failed','cancelled','partially_refunded','refunded','disputed')
            AND mode IN ('sandbox','live') AND amount > 0 AND captured_amount >= 0 AND refunded_amount >= 0 AND refunded_amount <= GREATEST(captured_amount, 0))");

        // Everything that ever happened to a payment, as seen from our side and the provider's. Never edited.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('type', 24); // create | confirm | capture | refund | void | dispute | sync | webhook | status
            $table->string('status', 24); // the payment status after this entry, or ok | error for calls
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('external_event_id', 160)->nullable(); // webhook event id: replays cannot write the same fact twice
            $table->char('request_id', 26)->nullable(); // correlation id of the request/job that caused it
            $table->jsonb('raw_response')->nullable(); // scrubbed: no card data, no secrets
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['payment_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX payment_transactions_event_once ON payment_transactions (payment_id, type, external_event_id) WHERE external_event_id IS NOT NULL');
        DB::statement('CREATE TRIGGER payment_transactions_append_only BEFORE UPDATE OR DELETE ON payment_transactions FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
        DB::statement('CREATE TRIGGER payment_transactions_no_truncate BEFORE TRUNCATE ON payment_transactions FOR EACH STATEMENT EXECUTE FUNCTION prevent_mutation()');

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 12)->default('pending'); // pending | succeeded | failed
            $table->string('reason', 300)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('failure_message', 500)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestampsTz();
            $table->index(['provider_reference']);
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_check CHECK (amount > 0 AND status IN ('pending','succeeded','failed'))");

        // Inbox: every provider notification is stored first (raw), then processed from the queue, as many times as it takes.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 24);
            $table->string('mode', 8)->nullable();
            $table->string('event_id', 160);
            $table->string('event_type', 120)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->text('raw_body');
            $table->jsonb('headers')->default('{}');
            $table->string('status', 12)->default('received'); // received | processing | processed | failed | dead | ignored | rejected
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->char('payment_id', 26)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->unique(['provider', 'event_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index('payment_id');
        });
        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_status_check CHECK (status IN ('received','processing','processed','failed','dead','ignored','rejected'))");

        // Outbox: domain events are written in the same transaction as the change and relayed afterwards, so a crash between
        // "payment captured" and "order created" cannot lose the second half.
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 80);
            $table->jsonb('payload');
            $table->string('status', 12)->default('pending'); // pending | dispatched | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        foreach (['outbox_messages', 'webhook_events', 'refunds', 'payment_transactions', 'payments', 'payment_providers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
