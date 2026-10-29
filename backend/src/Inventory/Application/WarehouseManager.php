<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;

final class WarehouseManager
{
    public function __construct(private readonly WarehouseRepository $warehouses) {}

    /** @param array<string, mixed> $input code, name, address, market_code, is_default, is_active */
    public function create(array $input): int
    {
        $data = $this->validated($input);
        ! $this->warehouses->codeTaken($data['code']) || throw new Conflict("The warehouse {$data['code']} already exists.", 'WAREHOUSE_EXISTS');

        return $this->warehouses->create($data + ['is_default' => (bool) ($input['is_default'] ?? $this->warehouses->all() === [])]);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): void
    {
        $current = $this->warehouses->find($id) ?? throw new NotFound('The warehouse');
        $data = $this->validated($input + ['market_code' => $current['market_code']] + $current);
        ! $this->warehouses->codeTaken($data['code'], $id) || throw new Conflict("The warehouse {$data['code']} already exists.", 'WAREHOUSE_EXISTS');

        $isDefault = (bool) ($input['is_default'] ?? $current['is_default']);
        ($isDefault || ! $current['is_default']) || throw new Conflict('Pick another default warehouse instead of unsetting this one.', 'DEFAULT_WAREHOUSE');
        (($data['is_active'] ?? true) || ! $current['is_default']) || throw new Conflict('The default warehouse cannot be deactivated.', 'DEFAULT_WAREHOUSE');

        $this->warehouses->update($id, $data + ['is_default' => $isDefault]);
    }

    public function delete(int $id): void
    {
        $current = $this->warehouses->find($id) ?? throw new NotFound('The warehouse');
        ! $current['is_default'] || throw new Conflict('The default warehouse cannot be deleted.', 'DEFAULT_WAREHOUSE');
        ! $this->warehouses->hasStock($id) || throw new Conflict('The warehouse still has stock or history; deactivate it instead.', 'WAREHOUSE_HAS_STOCK');

        $this->warehouses->delete($id);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function validated(array $in): array
    {
        $code = strtoupper(trim((string) $in['code']));
        preg_match('/^[A-Z0-9_-]{2,24}$/', $code) || throw new InvalidMovement('A warehouse code is 2-24 letters, digits, dash or underscore.', 'INVALID_WAREHOUSE_CODE');
        $name = trim((string) $in['name']);
        ($name !== '' && mb_strlen($name) <= 120) || throw new InvalidMovement('A warehouse needs a name (up to 120 characters).', 'INVALID_WAREHOUSE_NAME');
        $market = isset($in['market_code']) && $in['market_code'] !== '' ? strtoupper((string) $in['market_code']) : null;
        $market === null || $this->warehouses->marketExists($market) || throw new NotFound("The market {$market}");

        return ['code' => $code, 'name' => $name, 'address' => (array) ($in['address'] ?? []), 'market_code' => $market, 'is_active' => (bool) ($in['is_active'] ?? true)];
    }
}
