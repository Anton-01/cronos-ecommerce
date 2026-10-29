<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Console;

use Cronos\Pricing\Application\ExchangeRateUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class UpdateExchangeRatesCommand extends Command
{
    protected $signature = 'pricing:update-rates';

    protected $description = 'Refresh exchange rates for the active currencies and append them to the history';

    public function handle(ExchangeRateUpdater $updater): int
    {
        try {
            $report = $updater->run((float) config('services.exchange_rates.max_change_percent', 25));
        } catch (\Throwable $e) {
            // Prices keep converting at the last known rate; someone needs to know the feed is down.
            Log::critical('Exchange rates could not be refreshed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->error('Could not refresh exchange rates: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($report['updated'] as $code => $rate) {
            $this->info("{$code} = {$rate}");
        }
        foreach ($report['skipped'] as $code => $why) {
            $this->warn("{$code} skipped: {$why}");
            Log::warning('Exchange rate not applied', ['currency' => $code, 'reason' => $why]);
        }

        return $report['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
