<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence;

use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\MarketModel;

final class EloquentMarketStore implements MarketStore
{
    public function find(int $id): ?array
    {
        return MarketModel::query()->find($id)?->toArray();
    }

    public function all(): array
    {
        return MarketModel::query()->orderBy('code')->get()->map(fn ($m) => $m->toArray())->all();
    }

    public function create(array $data): int
    {
        return MarketModel::query()->create($data)->id;
    }

    public function update(int $id, array $data): void
    {
        MarketModel::query()->findOrFail($id)->update($data);
    }

    public function delete(int $id): void
    {
        MarketModel::query()->findOrFail($id)->delete();
    }

    public function codeTaken(string $code, ?int $exceptId = null): bool
    {
        return MarketModel::query()->where('code', $code)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }
}
