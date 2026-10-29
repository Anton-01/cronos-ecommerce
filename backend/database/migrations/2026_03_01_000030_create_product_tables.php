<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable();
            $table->string('type', 16);
            $table->string('status', 16)->default('draft');
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            // sha1 of the sorted attribute-value ids of a variation: one row per combination.
            $table->char('combination_hash', 40)->nullable();

            // How the product is sold. Lives on the parent; variations inherit it unchanged.
            $table->string('sell_unit', 8)->default('piece');
            $table->decimal('quantity_step', 12, 3)->default(1);
            $table->decimal('min_quantity', 12, 3)->default(1);
            $table->decimal('max_quantity', 12, 3)->nullable();
            $table->boolean('sell_by_cut')->default(true);   // free length in steps ("2.5 m")
            $table->boolean('sell_by_roll')->default(false); // whole rolls ("1 roll of 25 m")
            $table->decimal('roll_length', 12, 3)->nullable();
            $table->decimal('roll_price', 14, 2)->nullable(); // null = roll_length x unit price

            // Interim base price in the store currency; Phase 4 moves it to per-currency product_prices.
            $table->decimal('price', 14, 2)->nullable();
            $table->decimal('weight', 10, 3)->nullable(); // kg per sell unit (per metre for ribbons)
            $table->jsonb('dimensions')->nullable();

            $table->boolean('manage_stock')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->decimal('low_stock_threshold', 12, 3)->nullable();
            // Denormalised read value. Written only by the inventory ledger (Phase 5).
            $table->decimal('stock_quantity', 12, 3)->default(0);

            $table->boolean('is_customizable')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('parent_id');
            $table->index(['status', 'type']);
        });

        // The self-reference is added once the primary key exists.
        Schema::table('products', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('products')->restrictOnDelete());

        DB::unprepared(<<<'SQL'
            ALTER TABLE products ALTER COLUMN sku TYPE citext;
            ALTER TABLE products
                ADD CONSTRAINT products_type_check CHECK (type IN ('simple','variable','variation','grouped')),
                ADD CONSTRAINT products_status_check CHECK (status IN ('draft','published','inactive')),
                ADD CONSTRAINT products_sell_unit_check CHECK (sell_unit IN ('piece','meter')),
                ADD CONSTRAINT products_parent_check CHECK ((type = 'variation') = (parent_id IS NOT NULL)),
                ADD CONSTRAINT products_price_check CHECK (price IS NULL OR price >= 0),
                ADD CONSTRAINT products_roll_price_check CHECK (roll_price IS NULL OR roll_price >= 0),
                ADD CONSTRAINT products_step_check CHECK (quantity_step > 0 AND min_quantity >= quantity_step),
                ADD CONSTRAINT products_max_check CHECK (max_quantity IS NULL OR max_quantity >= min_quantity),
                -- Pieces are whole numbers sold one by one: no cuts, no rolls.
                ADD CONSTRAINT products_piece_check CHECK (sell_unit <> 'piece' OR (quantity_step = 1 AND NOT sell_by_roll AND roll_length IS NULL AND roll_price IS NULL)),
                ADD CONSTRAINT products_roll_check CHECK ((sell_by_roll = false OR roll_length IS NOT NULL) AND (roll_length IS NULL OR roll_length > 0)),
                ADD CONSTRAINT products_meter_mode_check CHECK (sell_unit <> 'meter' OR sell_by_cut OR sell_by_roll);
            CREATE UNIQUE INDEX products_sku_unique ON products (sku) WHERE sku IS NOT NULL AND deleted_at IS NULL;
            CREATE UNIQUE INDEX products_combination_unique ON products (parent_id, combination_hash) WHERE parent_id IS NOT NULL AND deleted_at IS NULL;
            CREATE INDEX products_sku_trgm ON products USING gin ((sku::text) gin_trgm_ops);
        SQL);

        Schema::create('product_translations', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->string('slug');
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->primary(['product_id', 'locale']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE product_translations ADD COLUMN search_vector tsvector;
            CREATE UNIQUE INDEX product_translations_slug_unique ON product_translations (locale, slug) WHERE deleted_at IS NULL;
            CREATE INDEX product_translations_search_idx ON product_translations USING gin (search_vector);
            CREATE INDEX product_translations_name_trgm ON product_translations USING gin (name gin_trgm_ops);

            -- Weighted, accent-insensitive vector in the language of the row: name > short text > description.
            CREATE OR REPLACE FUNCTION product_translation_search_vector() RETURNS trigger AS $$
            DECLARE
                cfg regconfig := (CASE split_part(NEW.locale, '-', 1) WHEN 'es' THEN 'spanish' WHEN 'en' THEN 'english' ELSE 'simple' END)::regconfig;
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector(cfg, unaccent(coalesce(NEW.name, ''))), 'A') ||
                    setweight(to_tsvector(cfg, unaccent(coalesce(NEW.short_description, ''))), 'B') ||
                    setweight(to_tsvector(cfg, unaccent(coalesce(NEW.description, ''))), 'C');
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER product_translations_search_vector
                BEFORE INSERT OR UPDATE OF name, short_description, description, locale ON product_translations
                FOR EACH ROW EXECUTE FUNCTION product_translation_search_vector();
        SQL);

        Schema::create('product_categories', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->primary(['product_id', 'category_id']);
            $table->index('category_id');
        });
        DB::statement('CREATE UNIQUE INDEX product_categories_one_primary ON product_categories (product_id) WHERE is_primary');

        Schema::create('product_tag', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['product_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUlid('attribute_id')->constrained('attributes')->restrictOnDelete();
            $table->boolean('is_variation')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->primary(['product_id', 'attribute_id']);
        });

        // Parent rows = values on offer; variation rows = the combination that variation represents.
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUlid('attribute_value_id')->constrained('attribute_values')->restrictOnDelete();
            $table->primary(['product_id', 'attribute_value_id']);
            $table->index('attribute_value_id');
        });

        Schema::create('product_group_items', function (Blueprint $table) {
            $table->foreignUlid('group_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('default_quantity', 12, 3)->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->primary(['group_id', 'product_id']);
        });
        DB::statement('ALTER TABLE product_group_items ADD CONSTRAINT product_group_items_self_check CHECK (group_id <> product_id)');

        Schema::create('product_preview_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // only the hash is stored; the link carries the secret
            $table->timestampTz('expires_at');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['product_preview_tokens', 'product_group_items', 'product_attribute_values', 'product_attributes', 'product_tag', 'product_categories', 'product_translations'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS product_translation_search_vector() CASCADE');
        Schema::dropIfExists('products');
    }
};
