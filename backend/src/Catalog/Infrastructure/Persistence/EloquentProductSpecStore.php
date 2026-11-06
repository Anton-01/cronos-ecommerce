<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Ports\ProductSpecStore;
use Cronos\Catalog\Domain\Services\SpecStates;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductSpecModel;
use Illuminate\Support\Facades\DB;

final class EloquentProductSpecStore implements ProductSpecStore
{
    public function find(string $productId): ?array
    {
        $m = ProductSpecModel::query()->find($productId);

        return $m ? ['product_id' => $m->product_id, 'product_type_id' => $m->product_type_id, 'specs' => $m->specs ?? [], 'states' => $m->states ?? []] : null;
    }

    public function save(string $productId, ?string $typeId, array $specs, array $states, ?string $userId): void
    {
        $m = ProductSpecModel::query()->find($productId) ?? new ProductSpecModel(['product_id' => $productId]);
        $m->fill(['product_type_id' => $typeId, 'specs' => $specs, 'states' => $states, 'updated_by' => $userId])->save();
    }

    public function withMissing(int $limit): array
    {
        $locale = config('app.locale_default');
        $rows = DB::table('product_specs as s')->join('products as p', 'p.id', '=', 's.product_id')->join('product_types as t', 't.id', '=', 's.product_type_id')
            ->leftJoin('product_translations as n', fn ($j) => $j->on('n.product_id', '=', 'p.id')->where('n.locale', $locale)->whereNull('n.deleted_at'))
            ->leftJoin('product_translations as pn', fn ($j) => $j->on('pn.product_id', '=', 'p.parent_id')->where('pn.locale', $locale)->whereNull('pn.deleted_at'))
            ->whereNull('p.deleted_at')->whereIn('p.type', ['simple', 'variable'])->orderByDesc('p.created_at')->limit(2000)
            ->get(['s.product_id', 's.specs', 's.states', 't.id as type_id', 't.required_fields', 'p.sku', 'p.status', DB::raw('COALESCE(n.name, pn.name) AS name')]);

        $out = [];
        foreach ($rows as $r) {
            $missing = SpecStates::missing(json_decode($r->required_fields, true) ?? [], json_decode($r->specs, true) ?? [], json_decode($r->states, true) ?? []);
            if ($missing !== []) {
                $out[] = ['product_id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'status' => $r->status, 'type_id' => $r->type_id, 'missing' => $missing];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
