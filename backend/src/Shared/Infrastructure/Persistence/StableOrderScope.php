<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Appends the primary key as the last ORDER BY criterion whenever a query is ordered.
 * Cursor/offset pagination over ties (e.g. many rows with the same created_at) would
 * otherwise return duplicated or missing rows across pages. Applied lazily, so it
 * always lands after the client-requested ordering.
 */
final class StableOrderScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! empty($builder->getQuery()->orders)) {
            $builder->orderBy($model->getQualifiedKeyName());
        }
    }
}
