<?php

declare(strict_types=1);

namespace Cronos\Inventory\Interfaces\GraphQL;

use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\StockAdjuster;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Application\WarehouseManager;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class InventoryMutations
{
    use ResolvesClientContext;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockAdjuster $adjuster,
        private readonly WarehouseManager $manager,
        private readonly WarehouseRepository $warehouses,
    ) {}

    /** @param array<string, mixed> $args */
    public function createWarehouse(mixed $root, array $args): array
    {
        return Present::warehouse($this->warehouses->find($this->manager->create($this->warehouse($args['input']))));
    }

    /** @param array<string, mixed> $args */
    public function updateWarehouse(mixed $root, array $args): array
    {
        $this->manager->update((int) $args['id'], $this->warehouse($args['input']));

        return Present::warehouse($this->warehouses->find((int) $args['id']));
    }

    /** @param array<string, mixed> $args */
    public function deleteWarehouse(mixed $root, array $args): bool
    {
        $this->manager->delete((int) $args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function recordMovement(mixed $root, array $args, GraphQLContext $context): string
    {
        $in = $args['input'];

        return $this->ledger->record(
            $in['productId'], (int) $in['warehouseId'], $in['type'], $in['quantity'], $this->actor($context), $in['increase'] ?? null, $in['unitCost'] ?? null,
            referenceType: 'manual', note: $in['note'] ?? null,
        );
    }

    /** @param array<string, mixed> $args */
    public function setStockCount(mixed $root, array $args, GraphQLContext $context): ?string
    {
        return $this->adjuster->setCount($args['productId'], (int) $args['warehouseId'], $args['counted'], $this->actor($context), $args['note'] ?? null);
    }

    /** @param array<string, mixed> $args */
    public function transferStock(mixed $root, array $args, GraphQLContext $context): array
    {
        $legs = $this->adjuster->transfer($args['productId'], (int) $args['fromWarehouseId'], (int) $args['toWarehouseId'], $args['quantity'], $this->actor($context), $args['note'] ?? null);

        return ['outId' => $legs['out'], 'inId' => $legs['in']];
    }

    /** @param array<string, mixed> $args */
    public function compensate(mixed $root, array $args, GraphQLContext $context): string
    {
        return $this->adjuster->compensate($args['id'], $args['reason'], $this->actor($context));
    }

    private function actor(GraphQLContext $context): Actor
    {
        return new Actor($context->user()?->getAuthIdentifier(), $this->clientContext($context));
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function warehouse(array $in): array
    {
        return ['code' => $in['code'], 'name' => $in['name'], 'address' => $in['address'] ?? [], 'market_code' => $in['marketCode'] ?? null, 'is_default' => $in['isDefault'] ?? null, 'is_active' => $in['isActive'] ?? true];
    }
}
