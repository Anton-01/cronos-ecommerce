<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Console;

use Cronos\Inventory\Application\ReservationService;
use Illuminate\Console\Command;

final class ExpireReservationsCommand extends Command
{
    protected $signature = 'inventory:expire-reservations';

    protected $description = 'Give back the stock held by carts and orders whose reservation ran out';

    public function handle(ReservationService $reservations): int
    {
        $n = $reservations->expireDue();
        $n === 0 || $this->info("Released {$n} expired reservation(s).");

        return self::SUCCESS;
    }
}
