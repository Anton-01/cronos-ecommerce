<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Interfaces\GraphQL;

use Cronos\CustomOrders\Application\CustomOrderService;
use Cronos\CustomOrders\Domain\Exceptions\CustomOrderError;
use Cronos\CustomOrders\Domain\Ports\CustomOrderRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminCustomOrders
{
    public function __construct(
        private readonly CustomOrderService $service,
        private readonly CustomOrderRepository $requests,
        private readonly CustomOrderPresenter $present,
    ) {}

    /** @param array<string, mixed> $args */
    public function list(mixed $root, array $args): array
    {
        $page = $this->requests->search($args['status'] ?? null, $args['search'] ?? null, $args['assignedTo'] ?? null, max(1, min((int) ($args['first'] ?? 25), 100)), max(0, (int) ($args['offset'] ?? 0)));

        return ['total' => $page['total'], 'rows' => array_map($this->present->admin(...), $page['rows'])];
    }

    /** @param array<string, mixed> $args */
    public function one(mixed $root, array $args): ?array
    {
        $r = $this->requests->find($args['id']);

        return $r === null ? null : $this->present->admin($r);
    }

    /** @param array<string, mixed> $args */
    public function assign(mixed $root, array $args): array
    {
        $this->service->assign($args['id'], $args['staffId'] ?? null);

        return $this->fresh($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function status(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->service->setStatus($args['id'], $args['to'], $args['reason'] ?? null, $this->uid($context));

        return $this->fresh($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function message(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->service->staffMessage($args['id'], $this->uid($context), $args['body'], (bool) ($args['internal'] ?? false));

        return $this->fresh($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function createQuote(mixed $root, array $args, GraphQLContext $context): array
    {
        $in = $args['input'];
        $q = $this->service->createQuote(
            $in['requestId'],
            array_map(static fn (array $l) => ['description' => $l['description'], 'unit' => $l['unit'], 'quantity' => $l['quantity'], 'unit_price' => $l['unitPrice'], 'tax_rate' => $l['taxRate'] ?? null], $in['lines']),
            $in['shipping'] ?? '0', $in['validUntil'] ?? null, $in['terms'] ?? null, $this->uid($context),
        );

        return $this->present->quote($q);
    }

    /** @param array<string, mixed> $args */
    public function sendQuote(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->present->quote($this->service->sendQuote($args['id'], $this->uid($context)));
    }

    /** @return array<string, mixed> */
    private function fresh(string $id): array
    {
        return $this->present->admin($this->requests->find($id) ?? throw new CustomOrderError('That request does not exist.', 'REQUEST_NOT_FOUND'));
    }

    private function uid(GraphQLContext $context): string
    {
        return (string) $context->user()->getAuthIdentifier();
    }
}
