<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Infrastructure\Console;

use Cronos\CustomOrders\Application\CustomOrderService;
use Illuminate\Console\Command;

final class ExpireQuotesCommand extends Command
{
    protected $signature = 'custom-orders:expire';

    protected $description = 'Expires quotes that were not accepted before their validity date';

    public function handle(CustomOrderService $service): int
    {
        $this->info('Expired quotes: '.$service->expireDue());

        return self::SUCCESS;
    }
}
