<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Console;

use Cronos\Inventory\Infrastructure\Persistence\StockCodeIssuer;
use Illuminate\Console\Command;

final class IssueStockCodesCommand extends Command
{
    protected $signature = 'warehouse:issue-codes';

    protected $description = 'Give every stockable product that has none its permanent warehouse code (existing codes are never changed)';

    public function handle(StockCodeIssuer $issuer): int
    {
        $this->info('Issued '.$issuer->issueMissing().' stock code(s).');

        return self::SUCCESS;
    }
}
