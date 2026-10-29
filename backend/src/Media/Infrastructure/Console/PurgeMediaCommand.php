<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Console;

use Cronos\Media\Application\PurgeDeletedMedia;
use DateTimeImmutable;
use Illuminate\Console\Command;

final class PurgeMediaCommand extends Command
{
    protected $signature = 'media:purge {--days= : Remove files of items deleted at least this many days ago}';

    protected $description = 'Physically remove the files of soft-deleted media past the retention period';

    public function handle(PurgeDeletedMedia $purge): int
    {
        $days = (int) ($this->option('days') ?? config('media.purge_after_days'));
        $total = 0;
        do {
            $n = $purge(new DateTimeImmutable("-{$days} days"));
            $total += $n;
        } while ($n > 0);

        $this->info("Purged the files of {$total} deleted media item(s).");

        return self::SUCCESS;
    }
}
