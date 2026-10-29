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
        Schema::create('shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('carrier', 40);
            $table->string('service', 80)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('tracking_number', 120)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->decimal('shipping_cost', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('carrier_shipment_id', 120)->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('estimated_delivery_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestampsTz();
            $table->index('order_id');
            $table->index(['carrier', 'tracking_number']);
        });
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('pending','in_transit','out_for_delivery','delivered','exception','returned','cancelled'))");

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->unique(['shipment_id', 'order_item_id']);
        });
        DB::statement('ALTER TABLE shipment_items ADD CONSTRAINT shipment_items_quantity_check CHECK (quantity > 0)');

        Schema::create('shipment_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
        });

        Schema::create('shipment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('event_key', 120); // the carrier's event id, or a hash of its content: replays are ignored
            $table->string('status', 20);
            $table->string('description', 500)->nullable();
            $table->string('location', 200)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->jsonb('raw')->nullable();
            $table->string('source', 12)->default('webhook');
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['shipment_id', 'event_key']);
            $table->index(['shipment_id', 'occurred_at']);
        });
        DB::statement('CREATE TRIGGER shipment_tracking_events_append_only BEFORE UPDATE OR DELETE ON shipment_tracking_events FOR EACH ROW EXECUTE FUNCTION prevent_mutation()');
    }

    public function down(): void
    {
        foreach (['shipment_tracking_events', 'shipment_packages', 'shipment_items', 'shipments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
