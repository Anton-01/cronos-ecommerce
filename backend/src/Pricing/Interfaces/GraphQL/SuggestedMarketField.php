<?php

declare(strict_types=1);

namespace Cronos\Pricing\Interfaces\GraphQL;

use Cronos\Pricing\Domain\Ports\PricingCatalog;

final class SuggestedMarketField
{
    public function __construct(private readonly PricingCatalog $catalog) {}

    /** @param array<string, mixed> $client */
    public function __invoke(array $client): ?string
    {
        if (($client['country'] ?? null) === null) {
            return null;
        }

        return collect($this->catalog->snapshot()['markets'])->first(fn (array $m) => $m['is_active'] && $m['country_code'] === $client['country'])['code'] ?? null;
    }
}
