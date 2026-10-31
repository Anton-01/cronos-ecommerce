<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Jobs;

use Cronos\Reports\Domain\Ports\ExportQueue;

final class QueueExportRunner implements ExportQueue
{
    public function push(string $exportId): void
    {
        RunExportJob::dispatch($exportId);
    }
}
