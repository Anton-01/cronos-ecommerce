<?php

declare(strict_types=1);

namespace Cronos\Wishlist\Interfaces\GraphQL;

use Cronos\Wishlist\Application\WishlistService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class WishlistResolver
{
    public function __construct(private readonly WishlistService $wishlist) {}

    /** @param array<string, mixed> $args */
    public function mine(mixed $root, array $args, GraphQLContext $context): array
    {
        $first = max(1, min((int) ($args['first'] ?? 24), 100));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $page = $this->wishlist->list($this->uid($context), $this->locale($args['locale'] ?? null), $first, $offset);

        return ['items' => $page['items'], 'total' => $page['total'], 'hasNextPage' => $offset + $first < $page['total'], 'endCursor' => null];
    }

    /** @param array<string, mixed> $args */
    public function contains(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->wishlist->saved($this->uid($context), array_slice($args['productIds'], 0, 200));
    }

    /** @param array<string, mixed> $args */
    public function add(mixed $root, array $args, GraphQLContext $context): bool
    {
        $this->wishlist->add($this->uid($context), $args['productId'], $this->locale($args['locale'] ?? null));

        return true;
    }

    /** @param array<string, mixed> $args */
    public function remove(mixed $root, array $args, GraphQLContext $context): bool
    {
        $this->wishlist->remove($this->uid($context), $args['productId']);

        return true;
    }

    private function locale(?string $requested): string
    {
        return in_array($requested, config('app.supported_locales'), true) ? $requested : (string) config('app.locale_default');
    }

    private function uid(GraphQLContext $context): string
    {
        return (string) $context->user()->getAuthIdentifier();
    }
}
