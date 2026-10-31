<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Jobs;

use Cronos\Reports\Application\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('exports');
        $this->afterCommit();
    }

    public function backoff(): int
    {
        return 60;
    }

    public function handle(ExportService $exports): void
    {
        $exports->run($this->exportId);
    }
}
