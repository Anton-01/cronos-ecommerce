<?php

use Cronos\Audit\Infrastructure\Persistence\AuditPartitioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Partitions for the current month and the next eleven; audit:partitions keeps the window moving. */
    public function up(): void
    {
        (new AuditPartitioner)->ensure(new DateTimeImmutable('today UTC'), 12);
    }

    public function down(): void {}
};
