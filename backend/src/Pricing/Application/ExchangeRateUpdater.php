<?php

declare(strict_types=1);

namespace Cronos\Pricing\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\ExchangeRateProvider;

/**
 * Pulls fresh rates for every active non-default currency. A rate that jumps more than the allowed
 * percentage is refused (a provider glitch must not reprice the shop overnight) and reported instead.
 */
final class ExchangeRateUpdater
{
    public function __construct(private readonly CurrencyStore $currencies, private readonly ExchangeRateProvider $provider, private readonly CurrencyManager $manager) {}

    /** @return array{updated: array<string, string>, skipped: array<string, string>} */
    public function run(float $maxChangePercent = 25.0): array
    {
        $all = $this->currencies->all();
        $default = collect($all)->firstWhere('is_default', true);
        $targets = collect($all)->filter(fn ($c) => ! $c['is_default'] && $c['is_active'])->values();
        $report = ['updated' => [], 'skipped' => []];
        if ($default === null || $targets->isEmpty()) {
            return $report;
        }

        $rates = $this->provider->fetch($default['code'], $targets->pluck('code')->all());

        foreach ($targets as $currency) {
            $code = $currency['code'];
            $new = $rates[$code] ?? null;
            if ($new === null || ! is_numeric($new) || BigDecimal::of($new)->isLessThanOrEqualTo(0)) {
                $report['skipped'][$code] = 'the provider returned no usable rate';

                continue;
            }
            $old = BigDecimal::of($currency['exchange_rate']);
            $change = BigDecimal::of($new)->minus($old)->abs()->multipliedBy(100)->dividedBy($old, 4, RoundingMode::HalfUp);
            if ($change->isGreaterThan((string) $maxChangePercent)) {
                $report['skipped'][$code] = "the rate moved {$change}% (limit {$maxChangePercent}%)";

                continue;
            }
            $this->manager->setRate($currency['id'], $new, $this->provider->name());
            $report['updated'][$code] = $new;
        }

        return $report;
    }
}
