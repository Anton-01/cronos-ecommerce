<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\Ports;

interface ExportRecipients
{
    public function emailOf(string $userId): ?string;
}
