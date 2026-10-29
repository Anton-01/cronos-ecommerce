<?php

declare(strict_types=1);

namespace Cronos\Pricing\Interfaces\GraphQL;

use Cronos\Pricing\Domain\Ports\PricingCatalog;

final class PublicMarketsQuery
{
    public function __construct(private readonly PricingCatalog $catalog) {}

    /** @return list<array<string, mixed>> */
    public function __invoke(): array
    {
        $snapshot = $this->catalog->snapshot();
        $currencies = collect($snapshot['currencies'])->keyBy('id');

        return collect($snapshot['markets'])->where('is_active', true)->map(function (array $m) use ($currencies): array {
            $c = $currencies[$m['currency_id']];

            return [
                'code' => $m['code'], 'name' => $m['name'], 'countryCode' => $m['country_code'], 'defaultLocale' => $m['default_locale'],
                'pricesIncludeTax' => $m['prices_include_tax'], 'currency' => ['code' => $c['code'], 'symbol' => $c['symbol'], 'decimals' => $c['decimals']],
            ];
        })->values()->all();
    }
}
