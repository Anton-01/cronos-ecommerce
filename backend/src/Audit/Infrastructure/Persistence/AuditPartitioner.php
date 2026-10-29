<?php

declare(strict_types=1);

namespace Cronos\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps monthly partitions of the audit trail ready ahead of time, so queries prune by date and old months can be archived.
 * A month that already has rows in the DEFAULT partition cannot be carved out of it (PostgreSQL refuses, and the table is
 * append-only so rows cannot be moved); those months are reported as skipped and stay in the default partition.
 */
final class AuditPartitioner
{
    /** @return array{created: list<string>, existing: list<string>, skipped: list<string>} */
    public function ensure(DateTimeImmutable $from, int $months): array
    {
        $report = ['created' => [], 'existing' => [], 'skipped' => []];
        $start = $from->modify('first day of this month')->setTime(0, 0);

        for ($i = 0; $i < $months; $i++) {
            $monthStart = $start->modify("+{$i} months");
            $monthEnd = $monthStart->modify('+1 month');
            $name = 'audits_p'.$monthStart->format('Y_m');

            if (DB::selectOne('SELECT to_regclass(?) AS t', [$name])->t !== null) {
                $report['existing'][] = $name;

                continue;
            }
            if (DB::table('audits_default')->where('created_at', '>=', $monthStart)->where('created_at', '<', $monthEnd)->exists()) {
                $report['skipped'][] = $name;

                continue;
            }

            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF audits FOR VALUES FROM ('%s+00') TO ('%s+00')",
                $name, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s'),
            ));
            $report['created'][] = $name;
        }

        return $report;
    }
}
