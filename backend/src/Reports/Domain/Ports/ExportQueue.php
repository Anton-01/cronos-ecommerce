<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\Ports;

interface ExportQueue
{
    public function push(string $exportId): void;
}
