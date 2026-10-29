<?php

declare(strict_types=1);

namespace Cronos\Promotions\Interfaces\GraphQL;

use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Promotions\Application\DiscountQuoter;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class PreviewDiscountsQuery
{
    private const MAX_ITEMS = 50;

    public function __construct(private readonly DiscountQuoter $quoter) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $in = $args['input'];
        count($in['items']) <= self::MAX_ITEMS || throw new InvalidPricing('A basket preview takes at most '.self::MAX_ITEMS.' lines.', 'TOO_MANY_ITEMS');
        $items = [];
        foreach ($in['items'] as $item) {
            (is_numeric($item['quantity']) && (float) $item['quantity'] > 0) || throw new InvalidPricing('Quantities must be greater than zero.', 'INVALID_QUANTITY');
            $items[] = ['product_id' => $item['productId'], 'quantity' => $item['quantity'], 'by_roll' => $item['byRoll'] ?? false];
        }
        $shipping = $in['shipping'] ?? null;
        $shipping === null || (is_numeric($shipping) && (float) $shipping >= 0) || throw new InvalidPricing('Shipping must be zero or more.', 'INVALID_SHIPPING');

        ['cart' => $cart, 'result' => $r, 'couponId' => $couponId] = $this->quoter->quote(
            $in['market'], $items, $in['couponCode'] ?? null, $context->user()?->getAuthIdentifier(), $shipping, false, $in['region'] ?? null,
        );

        $lines = [];
        foreach ($cart->lines as $i => $line) {
            $lines[] = ['index' => $i, 'total' => Present::money($line->total), 'discount' => Present::money($r->lineDiscounts[$line->id])];
        }

        return [
            'currency' => $cart->currency, 'subtotal' => Present::money($r->subtotal), 'itemsDiscount' => Present::money($r->itemsDiscount()),
            'shippingDiscount' => Present::money($r->shippingDiscount), 'totalDiscount' => Present::money($r->totalDiscount()),
            'applied' => array_map(fn (array $a) => ['kind' => $a['kind'], 'id' => $a['id'], 'name' => $a['name'], 'amount' => Present::money($a['amount'])], $r->applied),
            'lines' => $lines, 'couponApplied' => $couponId !== null,
        ];
    }
}
