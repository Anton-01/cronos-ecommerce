<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Catalog\Domain\Exceptions\InvalidQuantity;
use Cronos\Catalog\Domain\Exceptions\InvalidSalesUnit;

/**
 * How a product is sold: by the piece, or by the metre with a free cut (in fixed steps),
 * by whole rolls, or both. All arithmetic is exact decimal (no floats, so 0.1 + 0.2 == 0.3).
 */
final class SalesUnit
{
    public const PIECE = 'piece';

    public const METER = 'meter';

    public readonly BigDecimal $step;

    public readonly BigDecimal $min;

    public readonly ?BigDecimal $max;

    public readonly ?BigDecimal $rollLength;

    public readonly ?BigDecimal $rollPrice;

    public function __construct(
        public readonly string $unit,
        string|int|float $step = 1,
        string|int|float $min = 1,
        string|int|float|null $max = null,
        public readonly bool $sellByCut = true,
        public readonly bool $sellByRoll = false,
        string|int|float|null $rollLength = null,
        string|int|float|null $rollPrice = null,
    ) {
        in_array($unit, [self::PIECE, self::METER], true) || throw new InvalidSalesUnit("unknown unit [{$unit}]");

        $this->step = self::decimal($step);
        $this->min = self::decimal($min);
        $this->max = $max === null ? null : self::decimal($max);
        $this->rollLength = $rollLength === null ? null : self::decimal($rollLength);
        $this->rollPrice = $rollPrice === null ? null : self::decimal($rollPrice);

        $this->assertConsistent();
    }

    /** Pieces: whole numbers, one at a time, no cuts and no rolls. */
    public static function pieces(int $min = 1, ?int $max = null): self
    {
        return new self(self::PIECE, 1, $min, $max);
    }

    private function assertConsistent(): void
    {
        $this->step->isGreaterThan(0) || throw new InvalidSalesUnit('the step must be greater than zero');
        $this->min->isGreaterThanOrEqualTo($this->step) || throw new InvalidSalesUnit('the minimum cannot be smaller than the step');
        ! $this->max?->isLessThan($this->min) || throw new InvalidSalesUnit('the maximum cannot be smaller than the minimum');
        $this->isMultipleOfStep($this->min) || throw new InvalidSalesUnit('the minimum must be a multiple of the step');
        $this->max === null || $this->isMultipleOfStep($this->max) || throw new InvalidSalesUnit('the maximum must be a multiple of the step');

        if ($this->unit === self::PIECE) {
            ($this->step->isEqualTo(1) && $this->min->getScale() >= 0 && $this->min->isEqualTo($this->min->toScale(0, RoundingMode::Down)))
                || throw new InvalidSalesUnit('pieces are sold in whole numbers');
            ! ($this->sellByRoll || $this->rollLength !== null || $this->rollPrice !== null) || throw new InvalidSalesUnit('pieces have no rolls');

            return;
        }

        $this->sellByCut || $this->sellByRoll || throw new InvalidSalesUnit('enable selling by cut, by roll, or both');
        ! ($this->sellByRoll && $this->rollLength === null) || throw new InvalidSalesUnit('a roll length is required to sell rolls');
        ! ($this->rollLength !== null && ! $this->rollLength->isGreaterThan(0)) || throw new InvalidSalesUnit('the roll length must be greater than zero');
        ! ($this->rollPrice !== null && $this->rollPrice->isLessThan(0)) || throw new InvalidSalesUnit('the roll price cannot be negative');
    }

    public function isMeter(): bool
    {
        return $this->unit === self::METER;
    }

    /** Cut sale: a length in metres (or a count for pieces) that respects the minimum, maximum and step. */
    public function assertValidCut(string|int|float $quantity): void
    {
        $this->sellByCut || throw new InvalidQuantity(InvalidQuantity::MODE_NOT_ALLOWED, 'This product is sold by roll only.');
        $q = self::decimal($quantity);

        $q->isLessThan($this->min) && throw new InvalidQuantity(InvalidQuantity::BELOW_MIN, "The minimum is {$this->min->strippedOfTrailingZeros()}.");
        $this->max !== null && $q->isGreaterThan($this->max) && throw new InvalidQuantity(InvalidQuantity::ABOVE_MAX, "The maximum is {$this->max->strippedOfTrailingZeros()}.");
        $this->isMultipleOfStep($q) || throw new InvalidQuantity(InvalidQuantity::NOT_STEP_MULTIPLE, "The quantity must be a multiple of {$this->step->strippedOfTrailingZeros()}.");
    }

    /** Roll sale: a positive whole number of rolls. */
    public function assertValidRolls(int $rolls): void
    {
        $this->sellByRoll || throw new InvalidQuantity(InvalidQuantity::MODE_NOT_ALLOWED, 'This product is not sold by roll.');
        $rolls >= 1 || throw new InvalidQuantity(InvalidQuantity::BELOW_MIN, 'Order at least one roll.');
    }

    /** Metres leaving stock for an order of whole rolls. */
    public function metersForRolls(int $rolls): BigDecimal
    {
        $this->assertValidRolls($rolls);

        return $this->rollLength->multipliedBy($rolls);
    }

    /** Price of one roll: the explicit roll price (usually a volume discount) or length x metre price. */
    public function priceOfOneRoll(string|int|float $unitPrice): BigDecimal
    {
        $this->sellByRoll || throw new InvalidQuantity(InvalidQuantity::MODE_NOT_ALLOWED, 'This product is not sold by roll.');

        return ($this->rollPrice ?? $this->rollLength->multipliedBy(self::decimal($unitPrice)))->toScale(2, RoundingMode::HalfUp);
    }

    public function priceOfCut(string|int|float $unitPrice, string|int|float $quantity): BigDecimal
    {
        $this->assertValidCut($quantity);

        return self::decimal($unitPrice)->multipliedBy(self::decimal($quantity))->toScale(2, RoundingMode::HalfUp);
    }

    public function priceOfRolls(string|int|float $unitPrice, int $rolls): BigDecimal
    {
        $this->assertValidRolls($rolls);

        return $this->priceOfOneRoll($unitPrice)->multipliedBy($rolls)->toScale(2, RoundingMode::HalfUp);
    }

    private function isMultipleOfStep(BigDecimal $value): bool
    {
        return $value->remainder($this->step)->isZero();
    }

    /** @return array<string, mixed> Columns of the products table. */
    public function toColumns(): array
    {
        return [
            'sell_unit' => $this->unit,
            'quantity_step' => (string) $this->step,
            'min_quantity' => (string) $this->min,
            'max_quantity' => $this->max === null ? null : (string) $this->max,
            'sell_by_cut' => $this->sellByCut,
            'sell_by_roll' => $this->sellByRoll,
            'roll_length' => $this->rollLength === null ? null : (string) $this->rollLength,
            'roll_price' => $this->rollPrice === null ? null : (string) $this->rollPrice,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromColumns(array $row): self
    {
        return new self(
            $row['sell_unit'],
            $row['quantity_step'],
            $row['min_quantity'],
            $row['max_quantity'] ?? null,
            (bool) $row['sell_by_cut'],
            (bool) $row['sell_by_roll'],
            $row['roll_length'] ?? null,
            $row['roll_price'] ?? null,
        );
    }

    private static function decimal(string|int|float $value): BigDecimal
    {
        try {
            return BigDecimal::of(is_float($value) ? (string) $value : $value);
        } catch (\Throwable) {
            throw new InvalidSalesUnit("[{$value}] is not a valid number");
        }
    }
}
