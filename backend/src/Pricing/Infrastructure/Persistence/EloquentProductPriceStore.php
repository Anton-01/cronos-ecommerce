<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence;

use Cronos\Pricing\Domain\Ports\ProductPriceStore;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\ProductPriceModel;
use Illuminate\Support\Facades\DB;

final class EloquentProductPriceStore implements ProductPriceStore
{
    public function forProduct(string $productId): array
    {
        return DB::table('product_prices as pp')->join('currencies as c', 'c.id', '=', 'pp.currency_id')
            ->where('pp.product_id', $productId)->orderBy('c.code')
            ->get(['pp.*', 'c.code as currency_code'])->map(fn ($r) => $this->row((array) $r))->all();
    }

    public function upsert(string $productId, int $currencyId, array $data): void
    {
        $model = ProductPriceModel::query()->firstOrNew(['product_id' => $productId, 'currency_id' => $currencyId]);
        $model->fill($data)->save();
    }

    public function delete(string $productId, int $currencyId): void
    {
        ProductPriceModel::query()->where(['product_id' => $productId, 'currency_id' => $currencyId])->get()->each->delete();
    }

    public function productExists(string $productId): bool
    {
        return DB::table('products')->where('id', $productId)->whereNull('deleted_at')->exists();
    }

    public function basePrices(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('products as p')->leftJoin('products as parent', 'parent.id', '=', 'p.parent_id')
            ->whereIn('p.id', $productIds)
            ->selectRaw('p.id, COALESCE(p.price, parent.price) as price, COALESCE(p.roll_price, parent.roll_price) as roll_price, COALESCE(p.tax_class_id, parent.tax_class_id) as tax_class_id')
            ->get()->mapWithKeys(fn ($r) => [$r->id => ['price' => $r->price, 'roll_price' => $r->roll_price, 'tax_class_id' => $r->tax_class_id]])->all();
    }

    public function rowsFor(array $productIds, int $currencyId): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('product_prices')->whereIn('product_id', $productIds)->where('currency_id', $currencyId)
            ->get()->mapWithKeys(fn ($r) => [$r->product_id => $this->row((array) $r)])->all();
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function row(array $r): array
    {
        $trim = static fn (?string $v): ?string => $v === null ? null : (str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v);

        return [
            'product_id' => $r['product_id'], 'currency_id' => (int) $r['currency_id'], 'currency_code' => $r['currency_code'] ?? null,
            'price' => $trim($r['price']), 'roll_price' => $trim($r['roll_price']), 'compare_at_price' => $trim($r['compare_at_price']),
            'sale_price' => $trim($r['sale_price']), 'sale_starts_at' => $r['sale_starts_at'], 'sale_ends_at' => $r['sale_ends_at'],
        ];
    }
}
