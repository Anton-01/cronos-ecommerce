<?php

declare(strict_types=1);

namespace Cronos\Inventory\Interfaces\GraphQL;

use Cronos\Inventory\Application\InventoryReconciler;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Illuminate\Support\Str;

final class InventoryQuery
{
    private const MAX_PAGE = 100;

    public function __construct(
        private readonly InventoryQueries $queries,
        private readonly WarehouseRepository $warehouses,
        private readonly InventoryReconciler $reconciler,
    ) {}

    public function warehouses(): array
    {
        return array_map(Present::warehouse(...), $this->warehouses->all());
    }

    /** @param array<string, mixed> $args */
    public function stock(mixed $root, array $args): array
    {
        $page = $this->queries->stock($args['search'] ?? null, isset($args['warehouseId']) ? (int) $args['warehouseId'] : null, (bool) ($args['lowOnly'] ?? false), max(1, min((int) ($args['first'] ?? 25), self::MAX_PAGE)), max(0, (int) ($args['offset'] ?? 0)));

        return ['rows' => array_map(Present::stockRow(...), $page['rows']), 'total' => $page['total']];
    }

    public function lowStock(): array
    {
        return array_map(Present::stockRow(...), $this->queries->lowStock());
    }

    /** @param array<string, mixed> $args */
    public function kardex(mixed $root, array $args): array
    {
        $limit = max(1, min((int) ($args['first'] ?? 50), self::MAX_PAGE));
        $rows = $this->queries->movements($args['productId'] ?? null, isset($args['warehouseId']) ? (int) $args['warehouseId'] : null, $args['type'] ?? null, $args['before'] ?? null, $limit + 1);
        $page = array_slice($rows, 0, $limit);

        return [
            'entries' => array_map(fn (array $m) => Present::movement($m), $page),
            'nextBefore' => count($rows) > $limit ? (string) end($page)['id'] : null,
        ];
    }

    /** @param array<string, mixed> $args */
    public function reservations(mixed $root, array $args): array
    {
        return array_map(function (array $r): array {
            $out = [];
            foreach ($r as $k => $v) {
                $out[Str::camel($k)] = $v;
            }

            return $out;
        }, $this->queries->reservations($args['productId'] ?? null, 200));
    }

    public function health(): array
    {
        $r = $this->reconciler->check();

        return ['ok' => $r['ok'], 'levelMismatches' => $r['level_mismatches'], 'reservedMismatches' => $r['reserved_mismatches'], 'productMismatches' => $r['product_mismatches'], 'brokenChains' => $r['broken_chains']];
    }
}
