<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Same defence in depth as the Kardex: the runtime role can add payment ledger rows but never rewrite or erase them. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cronos_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON payment_transactions FROM cronos_app;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void {}
};
