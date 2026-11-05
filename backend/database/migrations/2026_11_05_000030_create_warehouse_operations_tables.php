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
        // ------------------------------------------------------------------ "leave it at N" requests that need a lead's decision
        Schema::create('stock_adjustment_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->char('location_id', 26);
            $table->decimal('seen_quantity', 14, 3);   // what the person saw on screen
            $table->unsignedBigInteger('seen_version'); // and which version of the shelf row that was
            $table->decimal('target_quantity', 14, 3);  // what they say is really there
            $table->decimal('delta', 14, 3);            // target - seen: the correction that will be applied
            $table->string('reason', 500);
            $table->string('status', 12)->default('pending');
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->char('requested_by', 26)->nullable();
            $table->timestampTz('requested_at', 6)->useCurrent();
            $table->char('decided_by', 26)->nullable();
            $table->timestampTz('decided_at', 6)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->char('movement_ulid', 26)->nullable();
            $table->index(['status', 'warehouse_id']);
            $table->foreign('location_id')->references('id')->on('warehouse_locations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_adjustment_requests ADD CONSTRAINT stock_adjustment_requests_status_check CHECK (status IN ('pending','approved','rejected'))");
        DB::statement('ALTER TABLE stock_adjustment_requests ADD CONSTRAINT stock_adjustment_requests_delta_check CHECK (delta <> 0 AND target_quantity >= 0)');

        // ------------------------------------------------------------------ count sessions
        Schema::create('count_sessions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('name', 120);
            $table->char('scope_location_id', 26)->nullable(); // null = the whole warehouse
            $table->string('status', 8)->default('open');
            $table->char('created_by', 26)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->char('closed_by', 26)->nullable();
            $table->timestampTz('closed_at', 6)->nullable();
            $table->foreign('scope_location_id')->references('id')->on('warehouse_locations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE count_sessions ADD CONSTRAINT count_sessions_status_check CHECK (status IN ('open','closed'))");

        // What the system said when the session began: a blind count is compared against this, not against whatever moved since.
        Schema::create('count_session_snapshots', function (Blueprint $table) {
            $table->char('session_id', 26);
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->char('location_id', 26);
            $table->decimal('quantity', 14, 3);
            $table->primary(['session_id', 'product_id', 'location_id']);
            $table->foreign('session_id')->references('id')->on('count_sessions')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('warehouse_locations')->restrictOnDelete();
        });

        Schema::create('count_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('session_id', 26);
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->char('location_id', 26);
            $table->decimal('counted', 14, 3);
            $table->decimal('system_at_start', 14, 3);
            $table->string('status', 10);
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->char('counted_by', 26)->nullable();
            $table->timestampTz('counted_at', 6)->useCurrent();
            $table->string('note', 500)->nullable();
            $table->char('reviewed_by', 26)->nullable();
            $table->timestampTz('reviewed_at', 6)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->char('movement_ulid', 26)->nullable();
            $table->index(['session_id', 'product_id', 'location_id']);
            $table->index(['status', 'session_id']);
            $table->foreign('session_id')->references('id')->on('count_sessions')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('warehouse_locations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE count_entries ADD CONSTRAINT count_entries_status_check CHECK (status IN ('confirmed','in_review','rejected'))");
        DB::statement('ALTER TABLE count_entries ADD CONSTRAINT count_entries_counted_check CHECK (counted >= 0)');

        // Workflow rows move forward once and never back; nothing is deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_adjustment_requests_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'A decided request never changes' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.id <> OLD.id OR NEW.product_id <> OLD.product_id OR NEW.location_id <> OLD.location_id OR NEW.delta <> OLD.delta OR NEW.target_quantity <> OLD.target_quantity
                   OR NEW.seen_quantity <> OLD.seen_quantity OR NEW.reason <> OLD.reason OR NEW.requested_by IS DISTINCT FROM OLD.requested_by OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Only the decision can be written on a request' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION count_entries_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'in_review' THEN
                    RAISE EXCEPTION 'A settled count never changes' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.id <> OLD.id OR NEW.session_id <> OLD.session_id OR NEW.product_id <> OLD.product_id OR NEW.location_id <> OLD.location_id OR NEW.counted <> OLD.counted
                   OR NEW.system_at_start <> OLD.system_at_start OR NEW.counted_by IS DISTINCT FROM OLD.counted_by OR NEW.counted_at <> OLD.counted_at THEN
                    RAISE EXCEPTION 'Only the review can be written on a count' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION count_sessions_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'closed' OR NEW.warehouse_id <> OLD.warehouse_id OR NEW.name <> OLD.name OR NEW.scope_location_id IS DISTINCT FROM OLD.scope_location_id OR NEW.created_at <> OLD.created_at THEN
                    RAISE EXCEPTION 'A count session only goes from open to closed' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('CREATE TRIGGER stock_adjustment_requests_guard BEFORE UPDATE ON stock_adjustment_requests FOR EACH ROW EXECUTE FUNCTION stock_adjustment_requests_guard()');
        DB::statement('CREATE TRIGGER count_entries_guard BEFORE UPDATE ON count_entries FOR EACH ROW EXECUTE FUNCTION count_entries_guard()');
        DB::statement('CREATE TRIGGER count_sessions_guard BEFORE UPDATE ON count_sessions FOR EACH ROW EXECUTE FUNCTION count_sessions_guard()');
        foreach (['stock_adjustment_requests', 'count_sessions', 'count_entries'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_no_delete BEFORE DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION prevent_mutation()");
        }
        DB::statement('CREATE TRIGGER count_session_snapshots_append_only BEFORE UPDATE OR DELETE ON count_session_snapshots FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
    }

    public function down(): void
    {
        foreach (['count_entries', 'count_session_snapshots', 'count_sessions', 'stock_adjustment_requests'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS stock_adjustment_requests_guard()');
        DB::statement('DROP FUNCTION IF EXISTS count_entries_guard()');
        DB::statement('DROP FUNCTION IF EXISTS count_sessions_guard()');
    }
};
