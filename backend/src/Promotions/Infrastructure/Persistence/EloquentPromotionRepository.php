<?php

declare(strict_types=1);

namespace Cronos\Promotions\Infrastructure\Persistence;

use Cronos\Promotions\Domain\Ports\PromotionRepository;
use Cronos\Promotions\Infrastructure\Persistence\Eloquent\PromotionModel;

final class EloquentPromotionRepository implements PromotionRepository
{
    public function find(string $id): ?array
    {
        return $this->row(PromotionModel::query()->find($id));
    }

    public function all(): array
    {
        return PromotionModel::query()->orderBy('priority')->orderByDesc('created_at')->get()->map(fn ($m) => $this->row($m))->all();
    }

    public function create(array $data): string
    {
        return PromotionModel::query()->create($data)->id;
    }

    public function update(string $id, array $data): void
    {
        PromotionModel::query()->findOrFail($id)->update($data);
    }

    public function delete(string $id): void
    {
        PromotionModel::query()->findOrFail($id)->delete();
    }

    /** @return array<string, mixed>|null */
    private function row(?PromotionModel $m): ?array
    {
        return $m === null ? null : [
            'id' => $m->id, 'name' => $m->name, 'description' => $m->description, 'priority' => $m->priority, 'is_stackable' => $m->is_stackable, 'is_active' => $m->is_active,
            'starts_at' => $m->starts_at?->toDateTimeString(), 'ends_at' => $m->ends_at?->toDateTimeString(), 'conditions' => $m->conditions ?? [], 'action' => $m->actions ?? [],
        ];
    }
}
