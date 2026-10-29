<?php

declare(strict_types=1);

namespace Cronos\Inventory\Interfaces\GraphQL;

use Illuminate\Support\Str;

final class Present
{
    /** @param array<string, mixed> $w @return array<string, mixed> */
    public static function warehouse(array $w): array
    {
        return ['id' => $w['id'], 'code' => $w['code'], 'name' => $w['name'], 'address' => $w['address'], 'marketCode' => $w['market_code'], 'isDefault' => $w['is_default'], 'isActive' => $w['is_active']];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function stockRow(array $r): array
    {
        $out = [];
        foreach ($r as $k => $v) {
            $out[Str::camel($k)] = $v;
        }
        $out['warehouses'] = array_map(fn (array $w) => ['warehouseId' => $w['warehouse_id'], 'warehouseCode' => $w['warehouse_code'], 'onHand' => $w['on_hand'], 'reserved' => $w['reserved'], 'available' => $w['available']], $r['warehouses'] ?? []);

        return $out;
    }

    /** @param array<string, mixed> $m @return array<string, mixed> */
    public static function movement(array $m): array
    {
        return [
            'id' => $m['ulid'], 'productId' => $m['product_id'], 'sku' => $m['sku'], 'productName' => $m['product_name'], 'warehouseId' => $m['warehouse_id'], 'warehouseCode' => $m['warehouse_code'],
            'type' => $m['type'], 'quantity' => $m['quantity'], 'unitCost' => $m['unit_cost'], 'balanceAfter' => $m['balance_after'], 'referenceType' => $m['reference_type'],
            'referenceId' => $m['reference_id'], 'note' => $m['note'], 'userEmail' => $m['user_email'], 'ip' => $m['ip'], 'country' => $m['country'], 'timezone' => $m['timezone'],
            'compensatedBy' => $m['compensated_by'], 'createdAt' => $m['created_at'], 'createdAtUtc' => $m['created_at']->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
