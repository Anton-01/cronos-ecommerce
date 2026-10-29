<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence;

use Cronos\Pricing\Domain\Ports\TaxStore;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\TaxClassModel;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\TaxRateModel;
use Illuminate\Support\Facades\DB;

final class EloquentTaxStore implements TaxStore
{
    public function classes(): array
    {
        return TaxClassModel::query()->orderByDesc('is_default')->orderBy('name')->get()->map(fn ($m) => $m->toArray())->all();
    }

    public function findClass(int $id): ?array
    {
        return TaxClassModel::query()->find($id)?->toArray();
    }

    public function createClass(array $data): int
    {
        return DB::transaction(function () use ($data): int {
            ! ($data['is_default'] ?? false) || TaxClassModel::query()->where('is_default', true)->update(['is_default' => false]);

            return TaxClassModel::query()->create($data)->id;
        });
    }

    public function updateClass(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            ! ($data['is_default'] ?? false) || TaxClassModel::query()->where('is_default', true)->where('id', '<>', $id)->update(['is_default' => false]);
            TaxClassModel::query()->findOrFail($id)->update($data);
        });
    }

    public function deleteClass(int $id): void
    {
        TaxClassModel::query()->findOrFail($id)->delete();
    }

    public function classInUse(int $id): bool
    {
        return DB::table('products')->where('tax_class_id', $id)->exists();
    }

    public function classNameTaken(string $name, ?int $exceptId = null): bool
    {
        return TaxClassModel::query()->where('name', $name)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public function rates(?int $taxClassId = null, ?int $marketId = null): array
    {
        return TaxRateModel::query()
            ->when($taxClassId, fn ($q) => $q->where('tax_class_id', $taxClassId))
            ->when($marketId, fn ($q) => $q->where('market_id', $marketId))
            ->orderBy('market_id')->orderBy('region')->orderBy('priority')
            ->get()->map(fn ($m) => $this->rate($m))->all();
    }

    public function findRate(int $id): ?array
    {
        $m = TaxRateModel::query()->find($id);

        return $m ? $this->rate($m) : null;
    }

    public function createRate(array $data): int
    {
        return TaxRateModel::query()->create($data)->id;
    }

    public function updateRate(int $id, array $data): void
    {
        TaxRateModel::query()->findOrFail($id)->update($data);
    }

    public function deleteRate(int $id): void
    {
        TaxRateModel::query()->findOrFail($id)->delete();
    }

    /** @return array<string, mixed> */
    private function rate(TaxRateModel $m): array
    {
        return [
            'id' => $m->id, 'tax_class_id' => $m->tax_class_id, 'market_id' => $m->market_id, 'region' => $m->region, 'name' => $m->name,
            'rate' => rtrim(rtrim((string) $m->rate, '0'), '.') ?: '0', 'priority' => $m->priority, 'is_compound' => $m->is_compound,
            'starts_at' => $m->starts_at?->toDateTimeString(), 'ends_at' => $m->ends_at?->toDateTimeString(),
        ];
    }
}
