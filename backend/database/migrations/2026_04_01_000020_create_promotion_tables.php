<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('type', 20); // fixed | percentage | free_shipping
            $table->decimal('value', 14, 4)->default(0);
            $table->foreignId('currency_id')->nullable()->constrained('currencies'); // required for fixed
            $table->decimal('min_spend', 14, 2)->nullable();
            $table->decimal('max_discount', 14, 2)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('is_stackable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('restrictions')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('ALTER TABLE coupons ADD COLUMN code citext NOT NULL');
        DB::statement('CREATE UNIQUE INDEX coupons_code_unique ON coupons (code) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE coupons
            ADD CONSTRAINT coupons_type_check CHECK (type IN ('fixed','percentage','free_shipping')),
            ADD CONSTRAINT coupons_value_check CHECK (value >= 0 AND (type <> 'percentage' OR value <= 100)),
            ADD CONSTRAINT coupons_fixed_currency CHECK (type <> 'fixed' OR currency_id IS NOT NULL),
            ADD CONSTRAINT coupons_window CHECK (starts_at IS NULL OR expires_at IS NULL OR starts_at < expires_at)");

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->char('user_id', 26)->nullable();
            $table->char('order_id', 26)->nullable(); // FK arrives with the orders table (Phase 7)
            $table->decimal('discount_amount', 14, 2);
            $table->char('currency', 3);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['coupon_id', 'user_id']);
            $table->unique(['coupon_id', 'order_id']);
        });
        DB::statement('CREATE TRIGGER coupon_usages_append_only BEFORE UPDATE OR DELETE ON coupon_usages FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');

        Schema::create('promotions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_stackable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->jsonb('conditions')->default('{}');
            $table->jsonb('actions')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('fraud_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('type', 40);
            $table->jsonb('params')->default('{}');
            $table->unsignedSmallInteger('score');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('fraud_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('order_id', 26)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedSmallInteger('score');
            $table->string('decision', 10);
            $table->jsonb('reasons')->default('[]');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('order_id');
        });
        DB::statement('CREATE TRIGGER fraud_checks_append_only BEFORE UPDATE OR DELETE ON fraud_checks FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
    }

    public function down(): void
    {
        foreach (['fraud_checks', 'fraud_rules', 'promotions', 'coupon_usages', 'coupons'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
