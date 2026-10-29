<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\ValueObjects;

use Cronos\Promotions\Domain\Exceptions\InvalidPromotion;

/** Who/what a coupon or promotion applies to. Empty lists mean "no restriction". */
final class Restrictions
{
    /**
     * @param  list<string>  $productIds
     * @param  list<string>  $categoryIds
     * @param  list<string>  $marketCodes
     */
    public function __construct(
        public readonly array $productIds = [],
        public readonly array $categoryIds = [],
        public readonly array $marketCodes = [],
        public readonly bool $excludeSaleItems = false,
        public readonly bool $firstOrderOnly = false,
        public readonly ?string $minSubtotal = null,
    ) {
        $minSubtotal === null || (is_numeric($minSubtotal) && (float) $minSubtotal >= 0) || throw new InvalidPromotion('The minimum spend must be zero or more.');
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $list = static fn (mixed $v): array => array_values(array_unique(array_map('strval', is_array($v) ? $v : [])));

        return new self(
            $list($a['product_ids'] ?? []), $list($a['category_ids'] ?? []), array_map('strtoupper', $list($a['market_codes'] ?? [])),
            (bool) ($a['exclude_sale_items'] ?? false), (bool) ($a['first_order_only'] ?? false), isset($a['min_subtotal']) && $a['min_subtotal'] !== '' ? (string) $a['min_subtotal'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'product_ids' => $this->productIds, 'category_ids' => $this->categoryIds, 'market_codes' => $this->marketCodes,
            'exclude_sale_items' => $this->excludeSaleItems, 'first_order_only' => $this->firstOrderOnly, 'min_subtotal' => $this->minSubtotal,
        ], static fn ($v) => $v !== [] && $v !== false && $v !== null);
    }

    public function appliesToMarket(string $market): bool
    {
        return $this->marketCodes === [] || in_array(strtoupper($market), $this->marketCodes, true);
    }

    public function targetsSpecificItems(): bool
    {
        return $this->productIds !== [] || $this->categoryIds !== [];
    }

    public function matches(CartLine $line): bool
    {
        if ($this->excludeSaleItems && $line->onSale) {
            return false;
        }
        if (! $this->targetsSpecificItems()) {
            return true;
        }

        return in_array($line->productId, $this->productIds, true) || array_intersect($line->categoryIds, $this->categoryIds) !== [];
    }
}
