<?php

declare(strict_types=1);

namespace Cronos\Checkout\Infrastructure\Persistence;

use Cronos\Catalog\Domain\ValueObjects\SalesUnit;
use Cronos\Checkout\Domain\Ports\SellableProducts;
use Illuminate\Support\Facades\DB;

final class DatabaseSellableProducts implements SellableProducts
{
    public function find(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('products as p')
            ->leftJoin('products as parent', 'parent.id', '=', 'p.parent_id')
            ->leftJoin('product_translations as t', fn ($j) => $j->on('t.product_id', '=', DB::raw('COALESCE(p.parent_id, p.id)'))->where('t.locale', config('app.locale_default'))->whereNull('t.deleted_at'))
            ->whereIn('p.id', $ids)->whereNull('p.deleted_at')->where('p.status', 'published')->whereIn('p.type', ['simple', 'variation'])
            ->where(fn ($q) => $q->whereNull('p.parent_id')->orWhere('parent.status', 'published'))
            ->get(['p.*', 't.name as translated_name']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = ['id' => $r->id, 'sku' => $r->sku, 'name' => $r->translated_name ?? (string) $r->sku, 'sales' => SalesUnit::fromColumns((array) $r)];
        }

        return $out;
    }

    public function marketCountry(string $marketCode): ?string
    {
        return DB::table('markets')->where('code', strtoupper($marketCode))->where('is_active', true)->value('country_code');
    }
}
