<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Appends the primary key (same direction as the last requested criterion) as the final ORDER BY whenever a query is ordered.
 * Cursor/offset pagination over ties (e.g. many rows with the same created_at) would
 * otherwise return duplicated or missing rows across pages. Applied lazily, so it
 * always lands after the client-requested ordering.
 */
final class StableOrderScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $orders = $builder->getQuery()->orders;

        if (! empty($orders)) {
            // Follow the requested direction so "newest first" stays true for ties (ULIDs are time-ordered).
            $last = end($orders);
            $direction = is_array($last) && strtolower((string) ($last['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $builder->orderBy($model->getQualifiedKeyName(), $direction);
        }
    }
}
