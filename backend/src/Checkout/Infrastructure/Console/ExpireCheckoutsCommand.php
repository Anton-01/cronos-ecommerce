<?php

declare(strict_types=1);

namespace Cronos\Checkout\Infrastructure\Console;

use Cronos\Checkout\Application\CheckoutService;
use Illuminate\Console\Command;

final class ExpireCheckoutsCommand extends Command
{
    protected $signature = 'checkout:expire';

    protected $description = 'Closes checkouts nobody paid in time and gives their stock back';

    public function handle(CheckoutService $checkouts): int
    {
        $this->info('Expired checkouts: '.$checkouts->expireDue());

        return self::SUCCESS;
    }
}
