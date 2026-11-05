<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\Services\LocationRules;

/** The shelf tree of each warehouse. Every edit leaves a new version behind; codes never change and are never reused. */
final class LocationManager
{
    public function __construct(private readonly LocationRepository $locations, private readonly WarehouseRepository $warehouses) {}

    /** @param array<string, mixed> $input parent_id, code, name, type, capacity, is_pickable, sort_order */
    public function create(int $warehouseId, array $input, ?string $userId = null): string
    {
        $this->warehouses->find($warehouseId) ?? throw new NotFound('The warehouse');
        $code = LocationRules::code((string) ($input['code'] ?? ''));
        $code !== LocationRules::RECEIVING_CODE || throw new Conflict('RECEPCION is the warehouse\'s own receiving area and already exists.', 'LOCATION_CODE_RESERVED');
        $this->locations->findByCode($warehouseId, $code) === null || throw new Conflict("The location {$code} already exists in this warehouse (codes are never reused).", 'LOCATION_EXISTS');

        $parentId = ($input['parent_id'] ?? null) ?: null;
        if ($parentId !== null) {
            $parent = $this->locations->find($parentId) ?? throw new NotFound('The parent location');
            $parent['warehouse_id'] === $warehouseId || throw new InvalidMovement('The parent belongs to another warehouse.', 'LOCATION_WAREHOUSE_MISMATCH');
            $parent['is_active'] || throw new InvalidMovement('The parent location is inactive.', 'LOCATION_INACTIVE');
            LocationRules::assertDepth($parent['depth'], 1, $this->maxDepth());
        }

        return $this->locations->create([
            'warehouse_id' => $warehouseId, 'parent_id' => $parentId, 'code' => $code, 'name' => LocationRules::name((string) ($input['name'] ?? '')),
            'type' => LocationRules::type((string) ($input['type'] ?? 'shelf')), 'capacity' => LocationRules::capacity($input['capacity'] ?? null),
            'is_pickable' => (bool) ($input['is_pickable'] ?? true), 'sort_order' => (int) ($input['sort_order'] ?? 0), 'created_by' => $userId,
        ]);
    }

    /** @param array<string, mixed> $input any of name, type, capacity, is_pickable, sort_order, is_active */
    public function update(string $id, array $input, ?string $userId = null): void
    {
        $current = $this->locations->find($id) ?? throw new NotFound('The location');
        $changes = [];
        isset($input['name']) && $changes['name'] = LocationRules::name((string) $input['name']);
        isset($input['type']) && $changes['type'] = LocationRules::type((string) $input['type']);
        array_key_exists('capacity', $input) && $changes['capacity'] = LocationRules::capacity($input['capacity']);
        isset($input['is_pickable']) && $changes['is_pickable'] = (bool) $input['is_pickable'];
        isset($input['sort_order']) && $changes['sort_order'] = (int) $input['sort_order'];

        if (isset($input['is_active']) && (bool) $input['is_active'] !== $current['is_active']) {
            if (! $input['is_active']) {
                ! $current['is_system'] || throw new Conflict('The receiving area cannot be deactivated.', 'LOCATION_SYSTEM');
                BigDecimal::of($this->locations->stockIn($this->locations->subtreeIds($id)))->isZero() || throw new Conflict('There is still stock in this location or below it; move it first.', 'LOCATION_NOT_EMPTY');
            }
            $changes['is_active'] = (bool) $input['is_active'];
        }
        (! $current['is_system'] || ! isset($changes['type'])) || throw new Conflict('The receiving area keeps its type.', 'LOCATION_SYSTEM');

        $changes === [] || $this->locations->revise($id, $changes, $userId);
    }

    /** Re-parents a location with everything below it. */
    public function move(string $id, ?string $newParentId, ?string $userId = null): void
    {
        $node = $this->locations->find($id) ?? throw new NotFound('The location');
        ! $node['is_system'] || throw new Conflict('The receiving area stays at the top.', 'LOCATION_SYSTEM');
        $depth = 0;
        if ($newParentId !== null) {
            $parent = $this->locations->find($newParentId) ?? throw new NotFound('The new parent');
            $parent['warehouse_id'] === $node['warehouse_id'] || throw new InvalidMovement('A location cannot change warehouse.', 'LOCATION_WAREHOUSE_MISMATCH');
            ! in_array($newParentId, $this->locations->subtreeIds($id), true) || throw new InvalidMovement('A location cannot go inside itself.', 'LOCATION_CYCLE');
            $depth = $parent['depth'];
        }
        LocationRules::assertDepth($depth, $this->locations->subtreeHeight($id), $this->maxDepth());
        $this->locations->relocate($id, $newParentId, $userId);
    }

    /** Every warehouse has a place for what arrived and is not put away yet. @return string its id */
    public function ensureReception(int $warehouseId): string
    {
        $existing = $this->locations->findByCode($warehouseId, LocationRules::RECEIVING_CODE);
        if ($existing !== null) {
            return $existing['id'];
        }

        return $this->locations->create(['warehouse_id' => $warehouseId, 'code' => LocationRules::RECEIVING_CODE, 'name' => 'Recepción', 'type' => 'receiving', 'is_pickable' => false, 'is_system' => true]);
    }

    /** @return list<array<string, mixed>> */
    public function tree(int $warehouseId): array
    {
        return $this->locations->all($warehouseId);
    }

    private function maxDepth(): int
    {
        return (int) config('inventory.max_location_depth', 6);
    }
}
