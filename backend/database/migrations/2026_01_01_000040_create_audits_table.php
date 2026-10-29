<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Immutable audit trail, range-partitioned by month on created_at.
     * A DEFAULT partition guarantees inserts never fail; the audit:partitions
     * command (scheduled) pre-creates upcoming monthly partitions.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audits (
                id              BIGINT GENERATED ALWAYS AS IDENTITY,
                user_type       VARCHAR(255) NULL,
                user_id         CHAR(26) NULL,
                event           VARCHAR(64) NOT NULL,
                auditable_type  VARCHAR(255) NOT NULL,
                auditable_id    CHAR(26) NOT NULL,
                old_values      JSONB NULL,
                new_values      JSONB NULL,
                url             TEXT NULL,
                ip_address      INET NULL,
                user_agent      VARCHAR(1023) NULL,
                tags            VARCHAR(255) NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at);

            CREATE TABLE audits_default PARTITION OF audits DEFAULT;

            CREATE INDEX audits_auditable_idx ON audits (auditable_type, auditable_id);
            CREATE INDEX audits_user_idx ON audits (user_id);
            CREATE INDEX audits_created_at_idx ON audits (created_at);

            CREATE TRIGGER audits_append_only BEFORE UPDATE OR DELETE ON audits
                FOR EACH ROW EXECUTE FUNCTION prevent_mutation();
            CREATE TRIGGER audits_no_truncate BEFORE TRUNCATE ON audits
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_mutation();
        SQL);

        // Defense in depth: the runtime role cannot rewrite history even if the triggers were dropped.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cronos_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON audits, audits_default, security_events FROM cronos_app;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audits CASCADE');
    }
};
