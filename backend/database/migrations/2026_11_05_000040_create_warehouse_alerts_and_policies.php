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
        // ------------------------------------------------------------------ what counts as "low": every change is a new row, the latest wins
        Schema::create('stock_threshold_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->char('location_id', 26)->nullable(); // null = the whole warehouse
            $table->char('scope_key', 26)->default('WAREHOUSE'); // location id, or WAREHOUSE: lets a unique index treat "no location" as a value
            $table->decimal('minimum', 14, 3);            // at or below: low
            $table->decimal('critical', 14, 3)->nullable(); // at or below: critical (zero is always "out")
            $table->boolean('removed')->default(false);   // "no threshold any more" is also a version, never a delete
            $table->char('changed_by', 26)->nullable();
            $table->timestampTz('changed_at', 6)->useCurrent();
            $table->index(['product_id', 'warehouse_id', 'scope_key', 'id']);
            $table->foreign('location_id')->references('id')->on('warehouse_locations')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_threshold_versions ADD CONSTRAINT stock_threshold_versions_check CHECK (minimum >= 0 AND (critical IS NULL OR (critical >= 0 AND critical <= minimum)))');
        DB::statement('CREATE TRIGGER stock_threshold_versions_append_only BEFORE UPDATE OR DELETE ON stock_threshold_versions FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');

        // Where each product+place is right now (ok / low / critical / out), so only a CHANGE speaks. Derived: can always be recomputed.
        Schema::create('stock_alert_states', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->char('scope_key', 26)->default('WAREHOUSE');
            $table->string('state', 8)->default('ok');
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestampTz('since')->useCurrent();
            $table->primary(['product_id', 'warehouse_id', 'scope_key']);
        });
        DB::statement("ALTER TABLE stock_alert_states ADD CONSTRAINT stock_alert_states_state_check CHECK (state IN ('ok','low','critical','out'))");

        // ------------------------------------------------------------------ the alerts themselves (inbox)
        Schema::create('warehouse_alerts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('process', 32);
            $table->string('severity', 8)->default('info');
            $table->string('title', 160);
            $table->text('detail');
            $table->jsonb('data')->nullable();
            $table->foreignUlid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->char('location_id', 26)->nullable();
            $table->string('dedupe_key', 160)->unique();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['process', 'created_at']);
        });
        DB::statement("ALTER TABLE warehouse_alerts ADD CONSTRAINT warehouse_alerts_severity_check CHECK (severity IN ('info','warning','critical'))");
        DB::statement('CREATE TRIGGER warehouse_alerts_append_only BEFORE UPDATE OR DELETE ON warehouse_alerts FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');

        Schema::create('warehouse_alert_recipients', function (Blueprint $table) {
            $table->char('alert_id', 26);
            $table->char('user_id', 26);
            $table->boolean('by_email')->default(false);
            $table->timestampTz('read_at')->nullable();
            $table->primary(['alert_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->foreign('alert_id')->references('id')->on('warehouse_alerts')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        // ------------------------------------------------------------------ who hears about what (only people of the system)
        Schema::create('alert_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('process', 32);
            $table->char('user_id', 26);
            $table->boolean('inbox')->default(true);
            $table->boolean('email')->default(true);
            $table->char('added_by', 26)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['process', 'user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        // Adding and removing leaves a trace nobody can erase.
        Schema::create('alert_subscription_log', function (Blueprint $table) {
            $table->id();
            $table->string('process', 32);
            $table->char('user_id', 26);
            $table->string('action', 8);
            $table->char('done_by', 26)->nullable();
            $table->timestampTz('done_at', 6)->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE alert_subscription_log ADD CONSTRAINT alert_subscription_log_action_check CHECK (action IN ('added','removed','changed'))");
        DB::statement('CREATE TRIGGER alert_subscription_log_append_only BEFORE UPDATE OR DELETE ON alert_subscription_log FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');

        // ------------------------------------------------------------------ the rules the admin can change (approval limits, hysteresis ...), each change a new row
        Schema::create('warehouse_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 48);
            $table->string('value', 64);
            $table->char('changed_by', 26)->nullable();
            $table->timestampTz('changed_at', 6)->useCurrent();
            $table->index(['key', 'id']);
        });
        DB::statement('CREATE TRIGGER warehouse_policy_versions_append_only BEFORE UPDATE OR DELETE ON warehouse_policy_versions FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
    }

    public function down(): void
    {
        foreach (['warehouse_policy_versions', 'alert_subscription_log', 'alert_subscriptions', 'warehouse_alert_recipients', 'warehouse_alerts', 'stock_alert_states', 'stock_threshold_versions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
