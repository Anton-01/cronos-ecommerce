<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pgcrypto', 'pg_trgm', 'citext', 'btree_gin', 'ltree'] as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }

        // Generic guard used by every append-only table (audit trail, kardex, etc.).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_mutation()');
    }
};
