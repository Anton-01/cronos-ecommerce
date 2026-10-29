<?php

declare(strict_types=1);

namespace Cronos\Wishlist\Application;

use Cronos\Catalog\Domain\Ports\StorefrontCatalog;
use Cronos\Wishlist\Domain\Exceptions\WishlistError;
use Cronos\Wishlist\Domain\Ports\WishlistRepository;

final class WishlistService
{
    public const MAX_ITEMS = 500;

    public function __construct(private readonly WishlistRepository $lists, private readonly StorefrontCatalog $catalog) {}

    /** Idempotent: adding twice is adding once. Only published products can be saved. */
    public function add(string $userId, string $productId, string $locale): void
    {
        $this->catalog->summariesOf($locale, [$productId]) !== [] || throw new WishlistError('That product is not available.', 'PRODUCT_UNAVAILABLE');
        $list = $this->lists->defaultListId($userId);
        if ($this->lists->has($list, $productId)) {
            return;
        }
        $this->lists->count($list) < self::MAX_ITEMS || throw new WishlistError('The wishlist is full.', 'WISHLIST_FULL');
        $this->lists->add($list, $productId);
    }

    public function remove(string $userId, string $productId): void
    {
        $this->lists->remove($this->lists->defaultListId($userId), $productId);
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    public function list(string $userId, string $locale, int $limit, int $offset): array
    {
        $list = $this->lists->defaultListId($userId);

        return ['total' => $this->lists->count($list), 'items' => $this->catalog->summariesOf($locale, $this->lists->productIds($list, $limit, $offset))];
    }

    /** @param list<string> $productIds @return list<string> */
    public function saved(string $userId, array $productIds): array
    {
        return $this->lists->containing($this->lists->defaultListId($userId), $productIds);
    }
}
