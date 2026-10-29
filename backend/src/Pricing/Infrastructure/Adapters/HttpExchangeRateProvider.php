<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Adapters;

use Cronos\Pricing\Domain\Ports\ExchangeRateProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Any JSON endpoint shaped like {"rates": {"USD": 0.055}} (open.er-api.com by default); URL and timeout come from config. */
final class HttpExchangeRateProvider implements ExchangeRateProvider
{
    public function name(): string
    {
        return 'http:'.parse_url((string) config('services.exchange_rates.url'), PHP_URL_HOST);
    }

    public function fetch(string $base, array $targets): array
    {
        $response = Http::timeout((int) config('services.exchange_rates.timeout', 10))->retry(2, 500)
            ->get(str_replace('{base}', $base, (string) config('services.exchange_rates.url')))
            ->throw();

        $rates = $response->json('rates');
        is_array($rates) || throw new RuntimeException('The exchange-rate provider returned an unexpected payload.');

        $out = [];
        foreach ($targets as $code) {
            isset($rates[$code]) && is_numeric($rates[$code]) && $out[$code] = number_format((float) $rates[$code], 8, '.', '');
        }

        return $out;
    }
}
