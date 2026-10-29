<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Pricing\Domain\Services\PriceResolver;
use Cronos\Pricing\Domain\Services\TaxCalculator;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Pricing\Domain\ValueObjects\PriceRow;
use Cronos\Pricing\Domain\ValueObjects\TaxRate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PricingRulesTest extends TestCase
{
    public function test_money_rounds_half_up_to_the_currency_scale_and_never_goes_negative(): void
    {
        $this->assertSame('10.01', (new Money('10.005', 'MXN'))->value());
        $this->assertSame('3.00', (new Money('10', 'MXN'))->percent(30)->value());
        $this->assertSame('0.00', (new Money('5', 'MXN'))->minus(new Money('9', 'MXN'))->value());
        $this->assertSame('0.30', (new Money('0.10', 'MXN'))->plus(new Money('0.20', 'MXN'))->value());
    }

    public function test_money_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidPricing::class);
        (new Money('1', 'MXN'))->plus(new Money('1', 'USD'));
    }

    public function test_mexican_iva_is_added_to_a_net_price(): void
    {
        $t = (new TaxCalculator)->fromNet(new Money('100', 'MXN'), [new TaxRate('IVA', 16)]);

        $this->assertSame(['16.00', '116.00'], [$t->tax->value(), $t->gross->value()]);
    }

    public function test_iva_is_taken_out_of_a_tax_inclusive_price_and_the_parts_add_up(): void
    {
        $t = (new TaxCalculator)->fromGross(new Money('116', 'MXN'), [new TaxRate('IVA', 16)]);
        $this->assertSame(['100.00', '16.00'], [$t->net->value(), $t->tax->value()]);

        // 99.99 / 1.16 does not divide evenly: net + tax must still equal exactly what the customer pays.
        $odd = (new TaxCalculator)->fromGross(new Money('99.99', 'MXN'), [new TaxRate('IVA', 16)]);
        $this->assertSame('99.99', $odd->net->plus($odd->tax)->value());
        $this->assertSame($odd->tax->value(), $odd->lines[0]['amount']->value());
    }

    public function test_state_plus_county_sales_tax_and_compound_rates(): void
    {
        $calc = new TaxCalculator;
        $stacked = $calc->fromNet(new Money('100', 'USD'), [new TaxRate('State', '6.25'), new TaxRate('County', '2', 1)]);
        $this->assertSame('8.25', $stacked->tax->value());

        $compound = $calc->fromNet(new Money('100', 'USD'), [new TaxRate('GST', 5), new TaxRate('PST', 10, 1, true)]);
        $this->assertSame('15.50', $compound->tax->value(), 'PST is charged on net + GST');

        $back = $calc->fromGross(new Money('115.50', 'USD'), [new TaxRate('GST', 5), new TaxRate('PST', 10, 1, true)]);
        $this->assertSame(['100.00', '15.50'], [$back->net->value(), $back->tax->value()]);
    }

    public function test_a_tax_rate_over_100_percent_is_refused(): void
    {
        $this->expectException(InvalidPricing::class);
        new TaxRate('Nope', 101);
    }

    public function test_price_resolution_in_the_default_currency_and_converted(): void
    {
        $r = new PriceResolver;
        $now = new DateTimeImmutable('2026-06-01 12:00:00');

        $mxn = $r->resolve('400.00', null, null, 'MXN', 2, true, '1', $now);
        $this->assertSame('400.00', $mxn->effective->value());
        $this->assertFalse($mxn->onSale);

        $usd = $r->resolve('400.00', null, null, 'USD', 2, false, '0.0555', $now);
        $this->assertSame('22.20', $usd->effective->value(), 'converted at the exchange rate');

        $own = $r->resolve('400.00', null, new PriceRow(price: '25.00'), 'USD', 2, false, '0.0555', $now);
        $this->assertSame('25.00', $own->effective->value(), 'a price set for the currency beats conversion');

        $this->assertNull($r->resolve(null, null, null, 'MXN', 2, true, '1', $now), 'no price anywhere = not for sale');
    }

    public function test_scheduled_sale_only_counts_inside_its_window_and_sets_the_strikethrough(): void
    {
        $r = new PriceResolver;
        $row = new PriceRow(salePrice: '300', saleStartsAt: new DateTimeImmutable('2026-06-01'), saleEndsAt: new DateTimeImmutable('2026-06-10'));

        $before = $r->resolve('400', null, $row, 'MXN', 2, true, '1', new DateTimeImmutable('2026-05-31 23:59:59'));
        $during = $r->resolve('400', null, $row, 'MXN', 2, true, '1', new DateTimeImmutable('2026-06-05'));
        $after = $r->resolve('400', null, $row, 'MXN', 2, true, '1', new DateTimeImmutable('2026-06-10'));

        $this->assertSame([false, '400.00', null], [$before->onSale, $before->effective->value(), $before->compareAt]);
        $this->assertSame([true, '300.00', '400.00'], [$during->onSale, $during->effective->value(), $during->compareAt->value()]);
        $this->assertFalse($after->onSale, 'the end instant is exclusive');
    }

    public function test_compare_at_shows_a_manual_strikethrough_only_when_it_is_higher(): void
    {
        $r = new PriceResolver;
        $now = new DateTimeImmutable('2026-06-01');

        $this->assertSame('500.00', $r->resolve('400', null, new PriceRow(compareAt: '500'), 'MXN', 2, true, '1', $now)->compareAt->value());
        $this->assertNull($r->resolve('400', null, new PriceRow(compareAt: '300'), 'MXN', 2, true, '1', $now)->compareAt);
    }

    public function test_a_sale_must_be_lower_than_the_regular_price_and_end_after_it_starts(): void
    {
        $this->expectException(InvalidPricing::class);
        new PriceRow(price: '100', salePrice: '100');
    }

    public function test_roll_price_converts_like_the_unit_price(): void
    {
        $p = (new PriceResolver)->resolve('18', '400', null, 'USD', 2, false, '0.05', new DateTimeImmutable('2026-01-01'));

        $this->assertSame(['0.90', '20.00'], [$p->effective->value(), $p->rollPrice->value()]);
    }
}
