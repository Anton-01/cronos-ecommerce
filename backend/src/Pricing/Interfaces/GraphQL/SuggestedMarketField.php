<?php

declare(strict_types=1);

namespace Cronos\Pricing\Interfaces\GraphQL;

use Cronos\Markets\Application\MarketResolver;

final class SuggestedMarketField
{
    public function __construct(private readonly MarketResolver $resolver) {}

    /** @param array<string, mixed> $client */
    public function __invoke(array $client): ?string
    {
        // A visitor whose country is unknown is not sent anywhere: only a known country maps to a store.
        return ($client['country'] ?? null) === null ? null : $this->resolver->forCountry($client['country']);
    }
}
