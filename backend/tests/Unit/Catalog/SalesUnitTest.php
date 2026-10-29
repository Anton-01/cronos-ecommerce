<?php

namespace Tests\Unit\Catalog;

use Cronos\Catalog\Domain\Exceptions\InvalidQuantity;
use Cronos\Catalog\Domain\Exceptions\InvalidSalesUnit;
use Cronos\Catalog\Domain\ValueObjects\SalesUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalesUnitTest extends TestCase
{
    /** A ribbon sold both ways: free cut in half-metre steps (1 m to 50 m) or whole 25 m rolls at a discount. */
    private function ribbon(): SalesUnit
    {
        return new SalesUnit(SalesUnit::METER, step: '0.5', min: 1, max: 50, sellByCut: true, sellByRoll: true, rollLength: 25, rollPrice: '400.00');
    }

    public function test_free_cuts_follow_the_step_minimum_and_maximum(): void
    {
        $ribbon = $this->ribbon();

        foreach ([1, '1.5', 2, '12.5', 50] as $ok) {
            $ribbon->assertValidCut($ok);
        }
        $this->addToAssertionCount(5);

        $this->assertSame('1.50', (string) $ribbon->priceOfCut('1.00', '1.5'));
    }

    #[DataProvider('badCuts')]
    public function test_invalid_cuts_are_rejected_with_a_specific_reason(string|int|float $quantity, string $reason): void
    {
        try {
            $this->ribbon()->assertValidCut($quantity);
            $this->fail("Expected [{$reason}] for {$quantity}");
        } catch (InvalidQuantity $e) {
            $this->assertSame($reason, $e->errorCode());
        }
    }

    public static function badCuts(): array
    {
        return [
            'below the minimum' => ['0.5', InvalidQuantity::BELOW_MIN],
            'zero' => [0, InvalidQuantity::BELOW_MIN],
            'above the maximum' => ['50.5', InvalidQuantity::ABOVE_MAX],
            'not a multiple of 0.5' => ['1.3', InvalidQuantity::NOT_STEP_MULTIPLE],
            'float noise is not tolerated' => [1.25, InvalidQuantity::NOT_STEP_MULTIPLE],
        ];
    }

    public function test_decimal_math_is_exact_where_floats_are_not(): void
    {
        // 0.1 + 0.2 != 0.3 in floating point; with a 0.1 step the cut 0.3 must be valid.
        $fine = new SalesUnit(SalesUnit::METER, step: '0.1', min: '0.1');
        $fine->assertValidCut('0.3');
        $this->assertSame('0.30', (string) $fine->priceOfCut('1.00', '0.3'));
        // 19.99 x 3 = 59.97 exactly; money rounds half-up to cents.
        $this->assertSame('59.97', (string) $fine->priceOfCut('19.99', '3.0'));
        $this->assertSame('0.67', (string) $fine->priceOfCut('3.333', '0.2'));
    }

    public function test_whole_rolls_convert_to_metres_and_use_the_roll_price(): void
    {
        $ribbon = $this->ribbon();

        $this->assertSame('75', (string) $ribbon->metersForRolls(3), 'stock leaves in metres');
        $this->assertSame('400.00', (string) $ribbon->priceOfOneRoll('18.00'), 'explicit roll price beats 25 x 18 = 450');
        $this->assertSame('1200.00', (string) $ribbon->priceOfRolls('18.00', 3));
    }

    public function test_without_a_roll_price_a_roll_costs_length_times_unit_price(): void
    {
        $ribbon = new SalesUnit(SalesUnit::METER, step: '0.5', min: 1, sellByRoll: true, rollLength: 25);

        $this->assertSame('450.00', (string) $ribbon->priceOfOneRoll('18.00'));
    }

    public function test_a_product_sold_only_by_roll_refuses_cuts_and_vice_versa(): void
    {
        $rollOnly = new SalesUnit(SalesUnit::METER, sellByCut: false, sellByRoll: true, rollLength: 10);
        $cutOnly = new SalesUnit(SalesUnit::METER, step: '0.5', min: 1);

        foreach ([fn () => $rollOnly->assertValidCut(2), fn () => $cutOnly->assertValidRolls(1), fn () => $cutOnly->priceOfOneRoll(5)] as $call) {
            try {
                $call();
                $this->fail('Expected the sale mode to be refused');
            } catch (InvalidQuantity $e) {
                $this->assertSame(InvalidQuantity::MODE_NOT_ALLOWED, $e->errorCode());
            }
        }
        $this->expectException(InvalidQuantity::class);
        $rollOnly->assertValidRolls(0);
    }

    public function test_pieces_are_whole_numbers(): void
    {
        $pieces = SalesUnit::pieces(min: 2, max: 10);
        $pieces->assertValidCut(2);
        $pieces->assertValidCut(10);

        $this->expectException(InvalidQuantity::class);
        $pieces->assertValidCut('2.5');
    }

    #[DataProvider('impossibleConfigurations')]
    public function test_inconsistent_configurations_cannot_be_built(array $args): void
    {
        $this->expectException(InvalidSalesUnit::class);

        new SalesUnit(...$args);
    }

    public static function impossibleConfigurations(): array
    {
        return [
            'unknown unit' => [['unit' => 'kilo']],
            'zero step' => [['unit' => 'meter', 'step' => 0]],
            'minimum below the step' => [['unit' => 'meter', 'step' => 1, 'min' => '0.5']],
            'minimum not a multiple of the step' => [['unit' => 'meter', 'step' => '0.5', 'min' => '1.2']],
            'maximum below the minimum' => [['unit' => 'meter', 'step' => 1, 'min' => 5, 'max' => 3]],
            'maximum not a multiple of the step' => [['unit' => 'meter', 'step' => '0.5', 'min' => 1, 'max' => '10.3']],
            'rolls without a roll length' => [['unit' => 'meter', 'sellByRoll' => true]],
            'negative roll price' => [['unit' => 'meter', 'sellByRoll' => true, 'rollLength' => 10, 'rollPrice' => -1]],
            'neither cut nor roll' => [['unit' => 'meter', 'sellByCut' => false, 'sellByRoll' => false]],
            'fractional pieces' => [['unit' => 'piece', 'step' => '0.5', 'min' => '0.5']],
            'pieces with rolls' => [['unit' => 'piece', 'sellByRoll' => true, 'rollLength' => 5]],
            'not a number' => [['unit' => 'meter', 'step' => 'abc']],
        ];
    }

    public function test_it_round_trips_through_database_columns(): void
    {
        $columns = $this->ribbon()->toColumns();

        $this->assertSame(['sell_unit' => 'meter', 'sell_by_cut' => true, 'sell_by_roll' => true], array_intersect_key($columns, array_flip(['sell_unit', 'sell_by_cut', 'sell_by_roll'])));
        $again = SalesUnit::fromColumns($columns);
        $this->assertSame($columns, $again->toColumns());
    }
}
