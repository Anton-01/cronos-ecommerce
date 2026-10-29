<?php

declare(strict_types=1);

namespace Cronos\Pricing\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Pricing\Domain\Exceptions\Conflict;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Pricing\Domain\Exceptions\NotFound;
use Cronos\Pricing\Domain\Ports\CurrencyStore;

/**
 * The default currency is the one product base prices are written in; it is created once (first currency)
 * and never changes, so every stored price keeps meaning the same thing. Currencies are never deleted: their exchange-rate
 * history is an immutable ledger, so a currency that is no longer sold in is deactivated.
 */
final class CurrencyManager
{
    public function __construct(private readonly CurrencyStore $currencies) {}

    /** @param array{code: string, name: string, symbol: string, decimals?: int, exchange_rate?: string} $data */
    public function create(array $data): int
    {
        $code = strtoupper(trim($data['code']));
        preg_match('/^[A-Z]{3}$/', $code) || throw new InvalidPricing('A currency code is three letters (ISO 4217).');
        $this->currencies->findByCode($code) === null || throw new Conflict("The currency {$code} already exists.", 'CURRENCY_EXISTS');

        $first = $this->currencies->all() === [];
        $decimals = (int) ($data['decimals'] ?? 2);
        ($decimals >= 0 && $decimals <= 4) || throw new InvalidPricing('Decimals must be between 0 and 4.');
        $rate = $first ? '1' : $this->rate($data['exchange_rate'] ?? throw new InvalidPricing('An exchange rate is required.', 'RATE_REQUIRED'));

        return $this->currencies->create([
            'code' => $code, 'name' => trim($data['name']), 'symbol' => trim($data['symbol']), 'decimals' => $decimals,
            'exchange_rate' => $rate, 'is_default' => $first, 'is_active' => true,
        ]);
    }

    /** @param array{name?: string, symbol?: string, decimals?: int, is_active?: bool} $data */
    public function update(int $id, array $data): void
    {
        $current = $this->currencies->find($id) ?? throw new NotFound('The currency');

        if (isset($data['decimals']) && $data['decimals'] !== $current['decimals']) {
            ($data['decimals'] >= 0 && $data['decimals'] <= 4) || throw new InvalidPricing('Decimals must be between 0 and 4.');
            ! $this->currencies->inUse($id) || throw new Conflict('The decimals of a currency in use cannot change.', 'CURRENCY_IN_USE');
        }
        if (($data['is_active'] ?? true) === false) {
            ! $current['is_default'] || throw new Conflict('The default currency cannot be deactivated.', 'DEFAULT_CURRENCY');
            ! $this->currencies->usedByActiveMarket($id) || throw new Conflict('An active market sells in this currency; deactivate or move the market first.', 'CURRENCY_IN_USE');
        }

        $this->currencies->update($id, array_filter($data, static fn ($v) => $v !== null));
    }

    public function setRate(int $id, string $rate, string $source = 'manual'): void
    {
        $current = $this->currencies->find($id) ?? throw new NotFound('The currency');
        ! $current['is_default'] || throw new Conflict('The default currency is always 1.', 'DEFAULT_CURRENCY');

        $this->currencies->setRate($id, $this->rate($rate), $source);
    }

    private function rate(string $rate): string
    {
        (is_numeric($rate) && BigDecimal::of($rate)->isPositive()) || throw new InvalidPricing('The exchange rate must be greater than zero.', 'INVALID_RATE');

        return (string) BigDecimal::of($rate)->toScale(8, RoundingMode::HalfUp);
    }
}
