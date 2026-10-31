<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that differs from one country's store to another and is not a price: who sells (fiscal identity), how to reach them, what
 * shipping costs, which countries land on this store. A market with no row here still works (sensible defaults), so creating a market
 * never needs a second step before it can sell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_profiles', function (Blueprint $table) {
            $table->foreignId('market_id')->primary()->constrained('markets')->cascadeOnDelete();
            $table->string('legal_name', 160)->nullable();
            $table->string('trade_name', 120)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('tax_id_label', 20)->default('Tax ID');
            $table->string('tax_regime', 120)->nullable();
            $table->jsonb('fiscal_address')->default('{}');
            $table->string('invoice_series', 12)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('support_hours', 120)->nullable();
            $table->string('ops_email', 255)->nullable();
            $table->decimal('shipping_flat', 14, 2)->nullable();
            $table->decimal('shipping_free_over', 14, 2)->nullable();
            $table->boolean('is_fallback')->default(false);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX market_profiles_one_fallback ON market_profiles (is_fallback) WHERE is_fallback');
        DB::statement('ALTER TABLE market_profiles ADD CONSTRAINT market_profiles_shipping_check CHECK ((shipping_flat IS NULL OR shipping_flat >= 0) AND (shipping_free_over IS NULL OR shipping_free_over >= 0))');

        Schema::create('market_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->char('country_code', 2)->unique(); // one country lands on exactly one store
            $table->timestampTz('created_at')->useCurrent();
        });

        // The seller's identity as it was when the order was placed: an invoice must not change if the company data is edited later.
        DB::statement('ALTER TABLE orders ADD COLUMN issuer JSONB NULL');

        // Existing markets keep behaving as before: their own country, flat shipping from the old config, the oldest market as fallback.
        DB::statement('INSERT INTO market_countries (market_id, country_code) SELECT id, country_code FROM markets ON CONFLICT DO NOTHING');
        foreach (DB::table('markets')->orderBy('id')->get() as $i => $m) {
            $rule = config('checkout.shipping.'.$m->code);
            DB::table('market_profiles')->insert([
                'market_id' => $m->id, 'tax_id_label' => $m->country_code === 'MX' ? 'RFC' : ($m->country_code === 'US' ? 'EIN' : 'Tax ID'),
                'shipping_flat' => $rule['flat'] ?? null, 'shipping_free_over' => $rule['free_over'] ?? null, 'is_fallback' => $i === 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP COLUMN issuer');
        Schema::dropIfExists('market_countries');
        Schema::dropIfExists('market_profiles');
    }
};
