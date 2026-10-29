<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carts live in the database (not the session) so they follow the shopper across devices and survive restarts; a guest cart is
 * addressed by an unguessable token and merged into the account on login. A checkout is the frozen offer: prices, taxes, discounts,
 * shipping and addresses as they were when the shopper pressed "pay", with the stock reservation behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26)->nullable();
            $table->string('token_hash', 64)->unique(); // sha256 of the guest token: a leaked table does not leak carts
            $table->char('market_code', 2);
            $table->string('region', 8)->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->string('status', 12)->default('active');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_status_check CHECK (status IN ('active','converted','abandoned'))");
        DB::statement("CREATE UNIQUE INDEX carts_one_active_per_user ON carts (user_id) WHERE status = 'active' AND user_id IS NOT NULL");

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->char('product_id', 26);
            $table->decimal('quantity', 14, 3); // metres or pieces; whole rolls when by_roll
            $table->boolean('by_roll')->default(false);
            $table->timestampsTz();
            $table->unique(['cart_id', 'product_id']);
        });
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check CHECK (quantity > 0)');

        Schema::create('checkouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('cart_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('email', 255);
            $table->char('market_code', 2);
            $table->string('region', 8)->nullable();
            $table->char('currency', 3);
            $table->boolean('tax_included');
            $table->string('status', 16)->default('open');
            $table->jsonb('lines'); // frozen price, discount and tax per line
            $table->jsonb('applied_discounts')->default('[]');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('items_discount', 14, 2);
            $table->decimal('shipping', 14, 2);
            $table->decimal('shipping_discount', 14, 2);
            $table->decimal('tax', 14, 2);
            $table->decimal('total', 14, 2);
            $table->char('coupon_id', 26)->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->text('shipping_address'); // encrypted
            $table->text('billing_address');  // encrypted
            $table->unsignedSmallInteger('fraud_score')->default(0);
            $table->string('fraud_decision', 8)->default('allow');
            $table->jsonb('notes')->default('[]'); // anomalies and manual-review reasons, newest last
            $table->char('payment_id', 26)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestampsTz();
            $table->index(['status', 'expires_at']);
            $table->index('user_id');
            $table->index('cart_id');
        });
        DB::statement("ALTER TABLE checkouts ADD CONSTRAINT checkouts_status_check CHECK (status IN ('open','paid','expired','cancelled','needs_review'))");
        DB::statement('ALTER TABLE checkouts ADD CONSTRAINT checkouts_amounts_check CHECK (total >= 0 AND subtotal >= 0 AND shipping >= 0 AND tax >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
