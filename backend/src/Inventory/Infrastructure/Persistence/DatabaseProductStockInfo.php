<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\ProductStockInfo;
use Illuminate\Support\Facades\DB;

final class DatabaseProductStockInfo implements ProductStockInfo
{
    public function find(string $productId): ?array
    {
        $p = DB::table('products as p')
            ->leftJoin('product_translations as t', fn ($j) => $j->on('t.product_id', '=', 'p.id')->where('t.locale', config('app.locale_default'))->whereNull('t.deleted_at'))
            ->where('p.id', $productId)->whereNull('p.deleted_at')
            ->first(['p.id', 'p.type', 'p.sku', 't.name', 'p.manage_stock', 'p.allow_backorder', 'p.low_stock_threshold']);

        return $p === null ? null : [
            'id' => $p->id, 'type' => $p->type, 'sku' => $p->sku, 'name' => $p->name, 'manage_stock' => (bool) $p->manage_stock,
            'allow_backorder' => (bool) $p->allow_backorder, 'low_stock_threshold' => $p->low_stock_threshold === null ? null : (string) $p->low_stock_threshold,
        ];
    }
}
