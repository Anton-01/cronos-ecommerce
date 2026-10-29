<?php

declare(strict_types=1);

namespace Cronos\Promotions\Interfaces\GraphQL;

use Cronos\Pricing\Domain\ValueObjects\Money;
use Illuminate\Support\Str;

final class Present
{
    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function coupon(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[Str::camel($k)] = $v;
        }
        $out['restrictions'] = self::restrictions($row['restrictions']);

        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function promotion(array $row): array
    {
        return [
            'id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'], 'priority' => $row['priority'], 'isStackable' => $row['is_stackable'],
            'isActive' => $row['is_active'], 'startsAt' => $row['starts_at'], 'endsAt' => $row['ends_at'],
            'conditions' => self::restrictions($row['conditions']), 'action' => $row['action'],
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function restrictions(array $r): array
    {
        return [
            'productIds' => $r['product_ids'] ?? [], 'categoryIds' => $r['category_ids'] ?? [], 'marketCodes' => $r['market_codes'] ?? [],
            'excludeSaleItems' => (bool) ($r['exclude_sale_items'] ?? false), 'firstOrderOnly' => (bool) ($r['first_order_only'] ?? false), 'minSubtotal' => $r['min_subtotal'] ?? null,
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function restrictionsInput(?array $in): array
    {
        return $in === null ? [] : [
            'product_ids' => $in['productIds'] ?? [], 'category_ids' => $in['categoryIds'] ?? [], 'market_codes' => $in['marketCodes'] ?? [],
            'exclude_sale_items' => $in['excludeSaleItems'] ?? false, 'first_order_only' => $in['firstOrderOnly'] ?? false, 'min_subtotal' => $in['minSubtotal'] ?? null,
        ];
    }

    /** @return array{amount: string, currency: string} */
    public static function money(Money $m): array
    {
        return ['amount' => $m->value(), 'currency' => $m->currency];
    }
}
