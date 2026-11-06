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
        // Short lists the client edits from the admin (packaging types, units ...). Colours, materials and finishes are attributes: they already have their own editor.
        Schema::create('catalog_options', function (Blueprint $table) {
            $table->id();
            $table->string('catalog', 32);
            $table->string('code', 40);
            $table->jsonb('labels');            // {"es-MX": "Unitario", "en-US": "Single"}
            $table->jsonb('meta')->nullable();  // what the list needs besides its name (a unit knows if it is a length, a weight unit its factor ...)
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['catalog', 'code']);
            $table->index(['catalog', 'is_active', 'position']);
        });

        // A "kind of product": which fields are mandatory, which attributes define its variants, how it is sold and how its SKUs start.
        Schema::create('product_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 32)->unique();
            $table->jsonb('names');
            $table->string('sku_prefix', 8);
            $table->jsonb('sales');                    // sell_unit, quantity_step, min_quantity, sell_by_cut, sell_by_roll
            $table->jsonb('required_fields')->default('[]');
            $table->jsonb('variant_attribute_ids')->default('[]');
            $table->jsonb('product_attribute_ids')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        // What is known about each product beyond its sales data. A field with no value is "pending" unless someone said it does not apply.
        Schema::create('product_specs', function (Blueprint $table) {
            $table->char('product_id', 26)->primary();
            $table->char('product_type_id', 26)->nullable();
            $table->jsonb('specs')->default('{}');
            $table->jsonb('states')->default('{}');
            $table->char('updated_by', 26)->nullable();
            $table->timestampsTz();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('product_type_id')->references('id')->on('product_types')->nullOnDelete();
        });

        // Next number of each SKU prefix: taken with a row lock, so two people creating products at once never get the same one.
        Schema::create('sku_counters', function (Blueprint $table) {
            $table->string('prefix', 12)->primary();
            $table->unsignedBigInteger('next_number')->default(1);
        });
        DB::statement("ALTER TABLE product_types ADD CONSTRAINT product_types_prefix_check CHECK (sku_prefix ~ '^[A-Z0-9]{1,8}$')");
    }

    public function down(): void
    {
        foreach (['sku_counters', 'product_specs', 'product_types', 'catalog_options'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
