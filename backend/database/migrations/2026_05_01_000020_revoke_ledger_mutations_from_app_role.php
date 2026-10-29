<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Defence in depth for every append-only ledger: the runtime role cannot rewrite them even if a trigger were dropped. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cronos_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON inventory_movements, fraud_checks, coupon_usages, exchange_rate_history FROM cronos_app;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void {}
};
