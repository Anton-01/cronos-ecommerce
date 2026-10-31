<?php

declare(strict_types=1);

namespace Cronos\Markets\Domain\Ports;

interface MarketProfileRepository
{
    /**
     * The market with its profile (defaults filled in when it has none) and the countries that land on it.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $marketCode): ?array;

    /** @return list<array<string, mixed>> */
    public function profiles(): array;

    /** @param array<string, mixed> $changes profile columns */
    public function save(int $marketId, array $changes): void;

    /** @param list<string> $countries */
    public function setCountries(int $marketId, array $countries): void;

    /** @return array{market_id: int, code: string}|null who owns this country right now (explicit link only) */
    public function countryOwner(string $country): ?array;

    public function setFallback(int $marketId): void;

    /** Code of the active market this country should see: its explicit link, else a market of that country, else null. */
    public function marketForCountry(string $country): ?string;

    public function fallbackMarket(): ?string;

    /** Links the market to what it uses elsewhere (read only, for the admin overview). @return array{warehouses: list<array<string, mixed>>, payment_providers: list<string>, tax_rates: int} */
    public function links(string $marketCode): array;
}
