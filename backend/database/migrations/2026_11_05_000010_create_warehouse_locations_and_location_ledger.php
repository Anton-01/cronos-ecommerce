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
        // ------------------------------------------------------------------ the shelves
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->char('parent_id', 26)->nullable();
            // The label painted on the shelf and printed in its QR. Never edited, never reused: a movement made "at A-03-2" always means the same place.
            $table->string('code', 24);
            $table->string('name', 120);
            $table->string('type', 12)->default('shelf');
            $table->unsignedSmallInteger('depth')->default(1);
            // Ids of the ancestors and itself, dot-separated: finding a whole subtree is a prefix match.
            $table->text('path');
            $table->integer('sort_order')->default(0);
            $table->decimal('capacity', 14, 3)->nullable();
            $table->boolean('is_pickable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->char('created_by', 26)->nullable();
            $table->timestampsTz();
            $table->unique(['warehouse_id', 'code']); // no soft delete on purpose: a code is never freed
            $table->unique(['id', 'warehouse_id']);    // lets movements prove their location belongs to the warehouse they say
            $table->index('parent_id');
            $table->index(['warehouse_id', 'path']);
        });
        DB::statement('ALTER TABLE warehouse_locations ADD CONSTRAINT warehouse_locations_parent_fk FOREIGN KEY (parent_id) REFERENCES warehouse_locations (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE warehouse_locations ADD CONSTRAINT warehouse_locations_type_check CHECK (type IN ('zone','aisle','shelf','level','bin','receiving','other'))");
        DB::statement('ALTER TABLE warehouse_locations ADD CONSTRAINT warehouse_locations_capacity_check CHECK (capacity IS NULL OR capacity > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION warehouse_locations_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code OR NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id OR NEW.is_system IS DISTINCT FROM OLD.is_system OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'A location code, its warehouse and its origin never change' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.version < OLD.version THEN
                    RAISE EXCEPTION 'A location version only goes up' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('CREATE TRIGGER warehouse_locations_guard BEFORE UPDATE ON warehouse_locations FOR EACH ROW EXECUTE FUNCTION warehouse_locations_guard()');
        DB::statement('CREATE TRIGGER warehouse_locations_no_delete BEFORE DELETE ON warehouse_locations FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');

        // Every edit leaves the whole previous shape behind as a new row: the master row says what it is now, this says what it was and who changed it.
        Schema::create('warehouse_location_versions', function (Blueprint $table) {
            $table->id();
            $table->char('location_id', 26);
            $table->unsignedInteger('version');
            $table->jsonb('snapshot');
            $table->char('changed_by', 26)->nullable();
            $table->timestampTz('changed_at', 6)->useCurrent();
            $table->unique(['location_id', 'version']);
            $table->foreign('location_id')->references('id')->on('warehouse_locations')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER warehouse_location_versions_append_only BEFORE UPDATE OR DELETE ON warehouse_location_versions FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
        DB::statement('CREATE TRIGGER warehouse_location_versions_no_truncate BEFORE TRUNCATE ON warehouse_location_versions FOR EACH STATEMENT EXECUTE FUNCTION prevent_mutation()');

        // ------------------------------------------------------------------ what is on each shelf (derived: rebuildable from the movements)
        Schema::create('location_stock', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->char('location_id', 26);
            $table->decimal('quantity', 14, 3)->default(0);
            // Raised by every movement: a screen that remembers the version it saw can tell the data moved under it.
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['product_id', 'location_id']);
            $table->foreign('location_id')->references('id')->on('warehouse_locations')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE location_stock ADD CONSTRAINT location_stock_quantity_check CHECK (quantity >= 0)');

        // ------------------------------------------------------------------ the trail: every change of a shelf, never edited, never removed
        Schema::create('location_movements', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id');
            $table->char('location_id', 26);
            // The other end of a transfer (where it came from / went to); null for everything else.
            $table->char('counterpart_location_id', 26)->nullable();
            $table->decimal('quantity', 14, 3); // signed: + enters the location, - leaves it
            $table->string('type', 16);
            $table->decimal('balance_after', 14, 3); // what this location holds of this product right after the row
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('note', 500)->nullable();
            // Supplied by the device that made the request: the same tap sent twice (bad signal, double click) changes the shelf once.
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->char('created_by', 26)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('device', 255)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);
            $table->index(['product_id', 'location_id', 'id']);
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['created_by', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
        DB::statement('ALTER TABLE location_movements ADD CONSTRAINT location_movements_location_fk FOREIGN KEY (location_id, warehouse_id) REFERENCES warehouse_locations (id, warehouse_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE location_movements ADD CONSTRAINT location_movements_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE location_movements ADD CONSTRAINT location_movements_counterpart_fk FOREIGN KEY (counterpart_location_id) REFERENCES warehouse_locations (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE location_movements ADD CONSTRAINT location_movements_type_check CHECK (
            quantity <> 0 AND balance_after >= 0
            AND type IN ('initial','receive','move_in','move_out','pick','adjust','count_adjust','return','waste','compensation')
            AND (type NOT IN ('initial','receive','move_in','return') OR quantity > 0)
            AND (type NOT IN ('move_out','pick','waste') OR quantity < 0)
            AND (type NOT IN ('move_in','move_out') OR (counterpart_location_id IS NOT NULL AND counterpart_location_id <> location_id)))");
        // A movement can be compensated at most once.
        DB::statement("CREATE UNIQUE INDEX location_movements_one_compensation ON location_movements (reference_id) WHERE reference_type = 'location_movement'");
        DB::statement('CREATE TRIGGER location_movements_append_only BEFORE UPDATE OR DELETE ON location_movements FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
        DB::statement('CREATE TRIGGER location_movements_no_truncate BEFORE TRUNCATE ON location_movements FOR EACH STATEMENT EXECUTE FUNCTION prevent_mutation()');

        // ------------------------------------------------------------------ the permanent code of each item (what the QR carries)
        DB::statement('ALTER TABLE products ADD COLUMN stock_code CHAR(11)');
        DB::statement('CREATE UNIQUE INDEX products_stock_code_unique ON products (stock_code) WHERE stock_code IS NOT NULL');
        // Every code ever issued, kept even if the product row were removed: a printed label must never start pointing at something else.
        Schema::create('stock_codes_issued', function (Blueprint $table) {
            $table->char('code', 11)->primary();
            $table->char('product_id', 26);
            $table->timestampTz('issued_at')->useCurrent();
        });
        DB::statement('CREATE TRIGGER stock_codes_issued_append_only BEFORE UPDATE OR DELETE ON stock_codes_issued FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
        DB::statement('CREATE TRIGGER stock_codes_issued_no_truncate BEFORE TRUNCATE ON stock_codes_issued FOR EACH STATEMENT EXECUTE FUNCTION prevent_mutation()');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION products_stock_code_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.stock_code IS NOT NULL AND NEW.stock_code IS DISTINCT FROM OLD.stock_code THEN
                    RAISE EXCEPTION 'A stock code is permanent: it cannot be changed or removed' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.stock_code IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.stock_code IS NULL) THEN
                    -- Fails (unique violation) if this code was ever issued before, even to a product that no longer exists.
                    INSERT INTO stock_codes_issued (code, product_id) VALUES (NEW.stock_code, NEW.id);
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('CREATE TRIGGER products_stock_code_guard BEFORE INSERT OR UPDATE OF stock_code ON products FOR EACH ROW EXECUTE FUNCTION products_stock_code_guard()');

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cronos_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON location_movements, warehouse_location_versions, stock_codes_issued FROM cronos_app;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS products_stock_code_guard ON products');
        DB::statement('DROP FUNCTION IF EXISTS products_stock_code_guard()');
        DB::statement('DROP INDEX IF EXISTS products_stock_code_unique');
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS stock_code');
        foreach (['stock_codes_issued', 'location_movements', 'location_stock', 'warehouse_location_versions', 'warehouse_locations'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS warehouse_locations_guard()');
    }
};
