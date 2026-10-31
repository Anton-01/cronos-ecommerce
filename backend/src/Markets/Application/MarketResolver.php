<?php

declare(strict_types=1);

namespace Cronos\Markets\Application;

use Cronos\Markets\Domain\Ports\MarketProfileRepository;

/**
 * Which store a visitor should see, from their country alone (never from a cookie or a language header they could set). Like a global
 * retailer: your country's store when there is one, the default store otherwise, and a gentle suggestion when you browse another.
 */
final class MarketResolver
{
    public function __construct(private readonly MarketProfileRepository $profiles) {}

    /** The store for a country: explicit link → market of that country → default store → null (nothing configured). */
    public function forCountry(?string $country): ?string
    {
        $country = $country === null ? null : strtoupper(trim($country));
        $linked = $country !== null && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $this->profiles->marketForCountry($country) : null;

        return $linked ?? $this->profiles->fallbackMarket();
    }

    /**
     * What to tell a visitor browsing $selected from $country. The notice appears only when the visitor's own store exists and differs
     * from the one being browsed — an unknown country (VPN, private network) is never told they are "abroad".
     *
     * @return array{detected: array<string, mixed>, selected: array<string, mixed>, locale: string}|null
     */
    public function mismatch(string $selected, ?string $country): ?array
    {
        if ($country === null) {
            return null;
        }
        $own = $this->profiles->marketForCountry(strtoupper($country));
        $selected = strtoupper($selected);
        if ($own === null || $own === $selected) {
            return null;
        }
        $detected = $this->profiles->profile($own);
        $current = $this->profiles->profile($selected);
        if ($detected === null || $current === null || ! $current['is_active']) {
            return null;
        }

        return ['detected' => $detected, 'selected' => $current, 'locale' => $detected['default_locale']];
    }
}
