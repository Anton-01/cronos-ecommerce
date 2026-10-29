<?php

declare(strict_types=1);

namespace Cronos\Pricing\Interfaces\GraphQL;

use Cronos\Pricing\Domain\ValueObjects\Money;
use Illuminate\Support\Str;

/** Shapes domain values the way the GraphQL schema names them. */
final class Present
{
    /** @return array{amount: string, currency: string}|null */
    public static function money(?Money $m): ?array
    {
        return $m === null ? null : ['amount' => $m->value(), 'currency' => $m->currency];
    }

    /** @param array<string, mixed> $q a PriceQuoter quote @return array<string, mixed> */
    public static function pricing(array $q): array
    {
        return [
            'market' => $q['market'], 'currency' => $q['currency'], 'taxIncluded' => $q['tax_included'],
            'price' => self::money($q['price']), 'listPrice' => self::money($q['list_price']), 'compareAt' => self::money($q['compare_at']),
            'onSale' => $q['on_sale'], 'saleEndsAt' => $q['sale_ends_at']?->format('Y-m-d H:i:s'),
            'priceWithTax' => self::money($q['price_with_tax']), 'priceWithoutTax' => self::money($q['price_without_tax']), 'taxAmount' => self::money($q['tax_amount']),
            'taxLines' => array_map(fn (array $l) => ['name' => $l['name'], 'rate' => $l['rate'], 'amount' => self::money($l['amount'])], $q['tax_lines']),
            'rollPrice' => self::money($q['roll_price']), 'rollPriceWithTax' => self::money($q['roll_price_with_tax']),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function camel(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[Str::camel((string) $key)] = $value;
        }

        return $out;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function snake(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            $out[Str::snake((string) $key)] = $value;
        }

        return $out;
    }
}
