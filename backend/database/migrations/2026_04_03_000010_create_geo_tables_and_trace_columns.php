<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IP -> country (and optionally timezone/region). Loaded from a CSV with `php artisan geo:import`.
        Schema::create('geo_ip_ranges', function (Blueprint $table) {
            $table->id();
            $table->ipAddress('ip_from');
            $table->ipAddress('ip_to');
            $table->char('country_code', 2);
            $table->string('timezone', 64)->nullable();
            $table->string('region', 64)->nullable();
        });
        DB::statement('ALTER TABLE geo_ip_ranges ALTER COLUMN ip_from TYPE inet USING ip_from::inet, ALTER COLUMN ip_to TYPE inet USING ip_to::inet');
        DB::statement('CREATE INDEX geo_ip_ranges_from_idx ON geo_ip_ranges (ip_from)');
        DB::statement('ALTER TABLE geo_ip_ranges ADD CONSTRAINT geo_ip_ranges_order CHECK (ip_from <= ip_to)');

        // Every trail records the caller's country and the timezone their clock was in, next to the UTC instant.
        DB::statement('ALTER TABLE audits ADD COLUMN country_code CHAR(2) NULL, ADD COLUMN timezone VARCHAR(64) NULL');
        DB::statement('ALTER TABLE security_events ADD COLUMN country_code CHAR(2) NULL, ADD COLUMN timezone VARCHAR(64) NULL');
        DB::statement('ALTER TABLE fraud_checks ADD COLUMN country_code CHAR(2) NULL, ADD COLUMN timezone VARCHAR(64) NULL');
    }

    public function down(): void
    {
        foreach (['audits', 'security_events', 'fraud_checks'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN country_code, DROP COLUMN timezone");
        }
        Schema::dropIfExists('geo_ip_ranges');
    }
};
