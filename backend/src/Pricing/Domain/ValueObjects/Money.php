<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;

/**
 * An amount of money in one currency, exact decimal at the currency's own scale (MXN/USD: 2).
 * Every operation rounds half-up back to that scale, so what is shown is what is charged.
 */
final class Money
{
    public readonly BigDecimal $amount;

    public function __construct(BigDecimal|string|int $amount, public readonly string $currency, public readonly int $decimals = 2)
    {
        $this->amount = BigDecimal::of($amount)->toScale($decimals, RoundingMode::HalfUp);
    }

    public static function zero(string $currency, int $decimals = 2): self
    {
        return new self(0, $currency, $decimals);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency, $this->decimals);
    }

    /** Clamped at zero: a discount can never turn into a negative price. */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        $result = $this->amount->minus($other->amount);

        return new self($result->isNegative() ? 0 : $result, $this->currency, $this->decimals);
    }

    public function times(BigDecimal|string|int $factor): self
    {
        return new self($this->amount->multipliedBy($factor), $this->currency, $this->decimals);
    }

    /** @param string|int $percent e.g. "16" for 16 % */
    public function percent(string|int $percent): self
    {
        return new self($this->amount->multipliedBy($percent)->dividedBy(100, $this->decimals + 6, RoundingMode::HalfUp), $this->currency, $this->decimals);
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->amount->isLessThanOrEqualTo($other->amount) ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount->isEqualTo($other->amount);
    }

    public function value(): string
    {
        return (string) $this->amount;
    }

    private function assertSameCurrency(self $other): void
    {
        $this->currency === $other->currency || throw new InvalidPricing("Cannot combine {$this->currency} with {$other->currency}.", 'CURRENCY_MISMATCH');
    }
}
