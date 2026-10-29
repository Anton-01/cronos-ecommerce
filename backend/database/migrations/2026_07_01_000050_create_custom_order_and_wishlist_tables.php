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
        DB::statement('DROP SEQUENCE IF EXISTS custom_order_number_seq');
        DB::statement('CREATE SEQUENCE custom_order_number_seq START 1000');

        Schema::create('custom_order_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 24)->unique();
            $table->char('user_id', 26);
            $table->string('email', 255);
            $table->char('market_code', 2);
            $table->string('status', 12)->default('submitted');
            $table->string('title', 160);
            $table->text('description');
            $table->jsonb('specifications')->default('{}');
            $table->date('desired_date')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->char('assigned_to', 26)->nullable();
            $table->char('order_id', 26)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE custom_order_requests ADD CONSTRAINT custom_requests_status_check CHECK (status IN ('submitted','in_review','quoted','approved','rejected','converted','expired'))");

        Schema::create('custom_order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('request_id')->constrained('custom_order_requests')->cascadeOnDelete();
            $table->string('author_type', 8);
            $table->char('author_id', 26)->nullable();
            $table->text('body');
            $table->boolean('is_internal')->default(false); // staff notes the customer never sees
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['request_id', 'id']);
        });
        DB::statement("ALTER TABLE custom_order_messages ADD CONSTRAINT custom_messages_author_check CHECK (author_type IN ('customer','staff','system'))");

        Schema::create('custom_order_quotes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('request_id')->constrained('custom_order_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->char('currency', 3);
            $table->boolean('tax_included');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            $table->string('status', 12)->default('draft');
            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['request_id', 'version']);
        });
        DB::statement("ALTER TABLE custom_order_quotes ADD CONSTRAINT custom_quotes_status_check CHECK (status IN ('draft','sent','accepted','rejected','expired','superseded'))");
        // Only one quote can be the live offer of a request.
        DB::statement("CREATE UNIQUE INDEX custom_quotes_one_sent ON custom_order_quotes (request_id) WHERE status = 'sent'");

        Schema::create('custom_order_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('quote_id')->constrained('custom_order_quotes')->cascadeOnDelete();
            $table->string('description', 255);
            $table->string('unit', 8);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 14, 2);
            $table->decimal('total', 14, 2);
            $table->char('product_id', 26)->nullable();
        });
        DB::statement("ALTER TABLE custom_order_quote_lines ADD CONSTRAINT quote_lines_unit_check CHECK (unit IN ('piece','meter','roll','service')), ADD CONSTRAINT quote_lines_quantity_check CHECK (quantity > 0 AND unit_price >= 0)");

        Schema::create('wishlists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->string('name', 80)->default('Favoritos');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id');
        });
        DB::statement('CREATE UNIQUE INDEX wishlists_one_default ON wishlists (user_id) WHERE is_default AND deleted_at IS NULL');

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('wishlist_id')->constrained('wishlists')->cascadeOnDelete();
            $table->char('product_id', 26);
            $table->timestampTz('added_at')->useCurrent();
            $table->unique(['wishlist_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['wishlist_items', 'wishlists', 'custom_order_quote_lines', 'custom_order_quotes', 'custom_order_messages', 'custom_order_requests'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP SEQUENCE IF EXISTS custom_order_number_seq');
    }
};
