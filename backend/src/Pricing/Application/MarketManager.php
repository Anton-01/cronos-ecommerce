<?php

declare(strict_types=1);

namespace Cronos\Pricing\Application;

use Cronos\Pricing\Domain\Exceptions\Conflict;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Pricing\Domain\Exceptions\NotFound;
use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\MarketStore;

final class MarketManager
{
    public function __construct(private readonly MarketStore $markets, private readonly CurrencyStore $currencies) {}

    /** @param array<string, mixed> $data code, name, country_code, currency_id, default_locale, prices_include_tax, is_active */
    public function create(array $data): int
    {
        $clean = $this->validated($data);
        ! $this->markets->codeTaken($clean['code']) || throw new Conflict("The market {$clean['code']} already exists.", 'MARKET_EXISTS');

        return $this->markets->create($clean + ['is_active' => $data['is_active'] ?? true]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $current = $this->markets->find($id) ?? throw new NotFound('The market');
        $clean = $this->validated($data + $current);
        ! $this->markets->codeTaken($clean['code'], $id) || throw new Conflict("The market {$clean['code']} already exists.", 'MARKET_EXISTS');

        $this->markets->update($id, $clean + ['is_active' => $data['is_active'] ?? $current['is_active']]);
    }

    public function delete(int $id): void
    {
        $this->markets->find($id) ?? throw new NotFound('The market');

        $this->markets->delete($id);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validated(array $data): array
    {
        $code = strtoupper(trim((string) $data['code']));
        preg_match('/^[A-Z0-9_-]{2,12}$/', $code) || throw new InvalidPricing('A market code is 2-12 letters, digits, dash or underscore.');
        $country = strtoupper(trim((string) $data['country_code']));
        preg_match('/^[A-Z]{2}$/', $country) || throw new InvalidPricing('The country is a two-letter ISO code.');
        $locale = (string) ($data['default_locale'] ?? config('app.locale_default'));
        in_array($locale, config('app.supported_locales'), true) || throw new InvalidPricing("The locale {$locale} is not supported.");
        $currency = $this->currencies->find((int) $data['currency_id']) ?? throw new NotFound('The currency');
        $currency['is_active'] || throw new InvalidPricing('A market cannot use an inactive currency.', 'CURRENCY_INACTIVE');

        return [
            'code' => $code, 'name' => trim((string) $data['name']), 'country_code' => $country, 'currency_id' => $currency['id'],
            'default_locale' => $locale, 'prices_include_tax' => (bool) ($data['prices_include_tax'] ?? false),
        ];
    }
}
