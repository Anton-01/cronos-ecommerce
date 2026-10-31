<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Persistence;

use Cronos\Reports\Domain\Ports\ExportRecipients;
use Illuminate\Support\Facades\DB;

final class DatabaseExportRecipients implements ExportRecipients
{
    public function emailOf(string $userId): ?string
    {
        return DB::table('users')->where('id', $userId)->value('email');
    }
}
