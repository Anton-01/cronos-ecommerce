<?php

declare(strict_types=1);

namespace Cronos\Orders\Interfaces\GraphQL;

use Cronos\Orders\Domain\Ports\OrderRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class StorefrontOrders
{
    private const MAX_PAGE = 50;

    public function __construct(private readonly OrderRepository $orders, private readonly OrderPresenter $present) {}

    /** @param array<string, mixed> $args */
    public function mine(mixed $root, array $args, GraphQLContext $context): array
    {
        $rows = $this->orders->forUser((string) $context->user()->getAuthIdentifier(), max(1, min((int) ($args['first'] ?? 10), self::MAX_PAGE)), max(0, (int) ($args['offset'] ?? 0)));

        return ['total' => $this->orders->countForUser((string) $context->user()->getAuthIdentifier()), 'rows' => array_map($this->present->customer(...), $rows)];
    }

    /** @param array<string, mixed> $args */
    public function mineByNumber(mixed $root, array $args, GraphQLContext $context): ?array
    {
        $order = $this->orders->findByNumber($args['number']);

        return $order !== null && $order['user_id'] === (string) $context->user()->getAuthIdentifier() ? $this->present->customer($order) : null;
    }

    /** @param array<string, mixed> $args */
    public function track(mixed $root, array $args): ?array
    {
        $order = $this->orders->findByNumber($args['number']);

        return $order !== null && hash_equals(strtolower($order['email']), strtolower(trim($args['email']))) ? $this->present->customer($order) : null;
    }
}
