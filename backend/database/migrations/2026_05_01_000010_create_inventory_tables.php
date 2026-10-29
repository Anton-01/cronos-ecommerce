<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24);
            $table->string('name', 120);
            $table->jsonb('address')->default('{}');
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX warehouses_code_unique ON warehouses (code) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX warehouses_one_default ON warehouses (is_default) WHERE is_default AND deleted_at IS NULL');

        // The Kardex: every change of stock is a row that is never edited or removed. Corrections are new, compensating rows.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 14, 3); // signed: + enters, - leaves (metres or pieces, as the product is sold)
            $table->string('type', 24);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('balance_after', 14, 3); // warehouse on-hand right after this row
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent(); // microseconds: the hash covers the exact instant
            // Tamper evidence: each row hashes its own content plus the previous row of the same product+warehouse.
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);

            $table->index(['product_id', 'warehouse_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_qty_check CHECK (quantity <> 0)');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_check CHECK (
            type IN ('purchase','sale','return','adjustment','waste','transfer_in','transfer_out','reservation_consume')
            AND (type NOT IN ('purchase','return','transfer_in') OR quantity > 0)
            AND (type NOT IN ('sale','waste','transfer_out','reservation_consume') OR quantity < 0))");
        // A movement can be compensated at most once.
        DB::statement("CREATE UNIQUE INDEX inventory_movements_one_compensation ON inventory_movements (reference_id) WHERE reference_type = 'movement'");
        DB::statement('CREATE TRIGGER inventory_movements_append_only BEFORE UPDATE OR DELETE ON inventory_movements FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
        DB::statement('CREATE TRIGGER inventory_movements_no_truncate BEFORE TRUNCATE ON inventory_movements FOR EACH STATEMENT EXECUTE FUNCTION prevent_mutation()');

        // Derived from the Kardex and rebuildable from it (inventory:reconcile).
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->decimal('reserved', 14, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['product_id', 'warehouse_id']);
        });
        DB::statement('ALTER TABLE stock_levels ADD COLUMN available NUMERIC(14,3) GENERATED ALWAYS AS (on_hand - reserved) STORED');
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_reserved_check CHECK (reserved >= 0)');

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('holder_type', 16); // cart | order
            $table->string('holder_id', 64);
            $table->string('status', 12)->default('active'); // active | consumed | released | expired
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->index(['status', 'expires_at']);
            $table->index(['holder_type', 'holder_id']);
        });
        DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_check CHECK (quantity > 0 AND status IN ('active','consumed','released','expired') AND holder_type IN ('cart','order'))");
        DB::statement("CREATE UNIQUE INDEX inventory_reservations_one_active ON inventory_reservations (holder_type, holder_id, product_id, warehouse_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        foreach (['inventory_reservations', 'stock_levels', 'inventory_movements', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
