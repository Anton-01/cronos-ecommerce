<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name', 80);
            $table->string('symbol', 8);
            $table->unsignedSmallInteger('decimals')->default(2);
            // Units of this currency per 1 unit of the default currency (the default itself is always 1).
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('rate_updated_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE currencies
            ADD CONSTRAINT currencies_rate_check CHECK (exchange_rate > 0),
            ADD CONSTRAINT currencies_decimals_check CHECK (decimals BETWEEN 0 AND 4),
            ADD CONSTRAINT currencies_default_rate_check CHECK (NOT is_default OR exchange_rate = 1)');
        DB::statement('CREATE UNIQUE INDEX currencies_one_default ON currencies (is_default) WHERE is_default');

        Schema::create('exchange_rate_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('rate', 18, 8);
            $table->string('source', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['currency_id', 'id']);
        });
        DB::statement('CREATE TRIGGER exchange_rate_history_append_only BEFORE UPDATE OR DELETE ON exchange_rate_history FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');

        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name', 80);
            $table->char('country_code', 2);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('default_locale', 10)->default('es-MX');
            $table->boolean('prices_include_tax')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX tax_classes_one_default ON tax_classes (is_default) WHERE is_default');

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_class_id')->constrained('tax_classes')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->string('region', 10)->nullable(); // US state / MX state; null = whole market
            $table->string('name', 60);
            $table->decimal('rate', 7, 4);
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_compound')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
            $table->index(['tax_class_id', 'market_id', 'region']);
        });
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_rate_check CHECK (rate BETWEEN 0 AND 100)');

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete(); // null = default class (variations: parent's)
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('price', 14, 4)->nullable();
            $table->decimal('roll_price', 14, 4)->nullable();
            $table->decimal('compare_at_price', 14, 4)->nullable();
            $table->decimal('sale_price', 14, 4)->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->timestampsTz();
            $table->unique(['product_id', 'currency_id']);
        });
        DB::statement('ALTER TABLE product_prices
            ADD CONSTRAINT product_prices_nonneg CHECK (COALESCE(price,0) >= 0 AND COALESCE(roll_price,0) >= 0 AND COALESCE(compare_at_price,0) >= 0 AND COALESCE(sale_price,0) >= 0),
            ADD CONSTRAINT product_prices_window CHECK (sale_starts_at IS NULL OR sale_ends_at IS NULL OR sale_starts_at < sale_ends_at),
            ADD CONSTRAINT product_prices_sale_lower CHECK (sale_price IS NULL OR price IS NULL OR sale_price < price)');
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropConstrainedForeignId('tax_class_id'));
        foreach (['product_prices', 'tax_rates', 'tax_classes', 'markets', 'exchange_rate_history', 'currencies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
