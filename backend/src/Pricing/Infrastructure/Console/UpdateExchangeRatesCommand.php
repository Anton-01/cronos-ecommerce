<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Console;

use Cronos\Pricing\Application\ExchangeRateUpdater;
use Illuminate\Console\Command;

final class UpdateExchangeRatesCommand extends Command
{
    protected $signature = 'pricing:update-rates';

    protected $description = 'Refresh exchange rates for the active currencies and append them to the history';

    public function handle(ExchangeRateUpdater $updater): int
    {
        $report = $updater->run((float) config('services.exchange_rates.max_change_percent', 25));

        foreach ($report['updated'] as $code => $rate) {
            $this->info("{$code} = {$rate}");
        }
        foreach ($report['skipped'] as $code => $why) {
            $this->warn("{$code} skipped: {$why}");
        }

        return $report['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
