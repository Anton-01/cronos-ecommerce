<?php

declare(strict_types=1);

namespace Cronos\Audit\Infrastructure\Console;

use Cronos\Audit\Infrastructure\Persistence\AuditPartitioner;
use DateTimeImmutable;
use Illuminate\Console\Command;

final class EnsureAuditPartitionsCommand extends Command
{
    protected $signature = 'audit:partitions {--months=6 : How many months ahead (including the current one) must have a partition}';

    protected $description = 'Create the monthly partitions of the audit trail ahead of time';

    public function handle(AuditPartitioner $partitioner): int
    {
        $report = $partitioner->ensure(new DateTimeImmutable('today UTC'), max(1, (int) $this->option('months')));

        foreach ($report['created'] as $name) {
            $this->info("created {$name}");
        }
        foreach ($report['skipped'] as $name) {
            $this->warn("skipped {$name}: the default partition already holds rows of that month");
        }
        $this->line(count($report['existing']).' partition(s) already existed.');

        return self::SUCCESS;
    }
}
