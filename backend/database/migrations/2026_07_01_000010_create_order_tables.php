<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An order is the permanent record of a sale: everything the shopper saw (names, prices, taxes, addresses) is copied in, so a later
 * price or product change never rewrites history. The logistic status and the money status move independently; every move is a row in
 * the append-only order_histories.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq'); // migrate:fresh drops tables, not sequences
        DB::statement('CREATE SEQUENCE order_number_seq START 1000');

        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_number', 24)->unique();
            $table->string('order_type', 12)->default('standard');
            $table->char('user_id', 26)->nullable();
            $table->string('email', 255);
            $table->char('market_code', 2);
            $table->char('currency', 3);
            $table->boolean('tax_included');
            $table->string('status', 16);
            $table->string('payment_status', 20);
            $table->string('fulfillment_status', 20)->default('unfulfilled');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2);
            $table->decimal('shipping_total', 14, 2);
            $table->decimal('tax_total', 14, 2);
            $table->decimal('grand_total', 14, 2);
            $table->decimal('refunded_total', 14, 2)->default(0);
            $table->char('coupon_id', 26)->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->char('checkout_id', 26)->nullable()->unique(); // one order per paid checkout: the listener may run twice
            $table->char('source_id', 26)->nullable();              // custom order quote that produced it
            $table->char('payment_id', 26)->nullable();
            $table->text('customer_note')->nullable();
            $table->unsignedSmallInteger('fraud_score')->default(0);
            $table->string('fraud_decision', 8)->default('allow');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestampTz('placed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('email');
        });
        DB::statement("ALTER TABLE orders
            ADD CONSTRAINT orders_type_check CHECK (order_type IN ('standard','custom')),
            ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','processing','shipped','delivered','completed','cancelled','refunded')),
            ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid','paid','partially_refunded','refunded','disputed')),
            ADD CONSTRAINT orders_fulfillment_check CHECK (fulfillment_status IN ('unfulfilled','partial','shipped','delivered')),
            ADD CONSTRAINT orders_amounts_check CHECK (grand_total >= 0 AND refunded_total >= 0 AND refunded_total <= grand_total)");

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->char('product_id', 26)->nullable();
            $table->string('product_name', 255);
            $table->string('sku', 100)->nullable();
            $table->string('unit', 8); // piece | meter | roll
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('total', 14, 2);
            $table->jsonb('options')->default('{}');
            $table->timestampsTz();
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_unit_check CHECK (unit IN ('piece','meter','roll')), ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)");

        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type', 8);
            $table->text('data'); // encrypted JSON snapshot
            $table->unique(['order_id', 'type']);
        });
        DB::statement("ALTER TABLE order_addresses ADD CONSTRAINT order_addresses_type_check CHECK (type IN ('shipping','billing'))");

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('source', 12);
            $table->string('reference_id', 26)->nullable();
            $table->string('name', 160);
            $table->decimal('amount', 14, 2);
        });

        Schema::create('order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('payment_status', 20);
            $table->text('note')->nullable();
            $table->boolean('notify_customer')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['order_id', 'id']);
        });
        DB::statement('CREATE TRIGGER order_histories_append_only BEFORE UPDATE OR DELETE ON order_histories FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
    }

    public function down(): void
    {
        foreach (['order_histories', 'order_discounts', 'order_addresses', 'order_items', 'orders'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
