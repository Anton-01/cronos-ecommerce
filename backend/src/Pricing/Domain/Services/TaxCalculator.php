<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Services;

use Brick\Math\RoundingMode;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Pricing\Domain\ValueObjects\TaxBreakdown;
use Cronos\Pricing\Domain\ValueObjects\TaxRate;

/**
 * Adds tax to a net price, or takes it out of a tax-inclusive one. Rates apply in priority order;
 * a compound rate is charged on net + the taxes before it (Canadian-style), a simple one on net alone.
 * For tax-inclusive prices the net is solved first and gross - net is the tax, so the totals always add up.
 */
final class TaxCalculator
{
    /** @param list<TaxRate> $rates */
    public function fromNet(Money $net, array $rates): TaxBreakdown
    {
        $lines = [];
        $running = $net;
        $total = Money::zero($net->currency, $net->decimals);

        foreach ($this->ordered($rates) as $rate) {
            $base = $rate->isCompound ? $running : $net;
            $amount = $base->percent((string) $rate->rate);
            $lines[] = ['name' => $rate->name, 'rate' => (string) $rate->rate, 'amount' => $amount];
            $running = $running->plus($amount);
            $total = $total->plus($amount);
        }

        return new TaxBreakdown($net, $lines, $total, $net->plus($total));
    }

    /** @param list<TaxRate> $rates */
    public function fromGross(Money $gross, array $rates): TaxBreakdown
    {
        // The gross/net ratio is the same for any net, so it is found by pricing a net of 1 at high precision.
        $unit = $this->fromNet(new Money(1, $gross->currency, 12), $rates);
        $factor = $unit->gross->amount;
        $net = new Money($gross->amount->dividedBy($factor, $gross->decimals + 6, RoundingMode::HalfUp), $gross->currency, $gross->decimals);
        $forward = $this->fromNet($net, $rates);

        // Whatever rounding left over goes to the last tax line so that net + taxes == the price the customer sees.
        $tax = $gross->minus($net);
        $lines = $forward->lines;
        if ($lines !== []) {
            $sumOthers = Money::zero($gross->currency, $gross->decimals);
            foreach (array_slice($lines, 0, -1) as $l) {
                $sumOthers = $sumOthers->plus($l['amount']);
            }
            $lines[array_key_last($lines)]['amount'] = $tax->minus($sumOthers);
        }

        return new TaxBreakdown($net, $lines, $tax, $gross);
    }

    /**
     * @param  list<TaxRate>  $rates
     * @return list<TaxRate>
     */
    private function ordered(array $rates): array
    {
        usort($rates, static fn (TaxRate $a, TaxRate $b): int => $a->priority <=> $b->priority);

        return $rates;
    }
}
