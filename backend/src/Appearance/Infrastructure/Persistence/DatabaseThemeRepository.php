<?php

declare(strict_types=1);

namespace Cronos\Appearance\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Appearance\Domain\Ports\ThemeRepository;
use Cronos\Appearance\Infrastructure\Persistence\Eloquent\ThemeVersionModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseThemeRepository implements ThemeRepository
{
    private const JSON = ['tokens', 'assets', 'navigation', 'home', 'features'];

    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function published(): ?array
    {
        return $this->row(ThemeVersionModel::query()->where('status', 'published')->first());
    }

    public function draft(): ?array
    {
        return $this->row(ThemeVersionModel::query()->where('status', 'draft')->first());
    }

    public function find(string $id): ?array
    {
        return $this->row(ThemeVersionModel::query()->find($id));
    }

    public function versions(int $limit): array
    {
        return ThemeVersionModel::query()->orderByDesc('number')->limit($limit)->get(['id', 'number', 'status', 'name', 'notes', 'derived_from', 'created_by', 'published_by', 'published_at', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id, 'number' => (int) $m->number, 'status' => $m->status, 'name' => $m->name, 'notes' => $m->notes, 'derived_from' => $m->derived_from, 'created_by' => $m->created_by,
                'published_by' => $m->published_by, 'published_at' => $m->published_at?->utc()->format('Y-m-d H:i:s'), 'created_at' => $m->created_at?->utc()->format('Y-m-d H:i:s'),
            ])->all();
    }

    public function createDraft(array $content, ?string $derivedFrom, ?string $userId, ?string $name): array
    {
        $model = new ThemeVersionModel;
        $model->id = strtolower((string) Str::ulid());
        $model->number = ((int) ThemeVersionModel::query()->max('number')) + 1;
        $model->status = 'draft';
        $model->name = $name;
        $model->derived_from = $derivedFrom;
        $model->created_by = $userId;
        foreach (self::JSON as $k) {
            $model->{$k} = $content[$k] ?? ($k === 'home' ? [] : []);
        }
        $model->save();

        return $this->row($model);
    }

    public function updateDraft(string $id, array $changes): void
    {
        $model = ThemeVersionModel::query()->where('status', 'draft')->findOrFail($id);
        foreach ($changes as $k => $v) {
            $model->{$k} = $v;
        }
        $model->save();
    }

    public function deleteDraft(string $id): void
    {
        ThemeVersionModel::query()->where('status', 'draft')->whereKey($id)->delete();
    }

    public function publish(string $draftId, ?string $userId, ?string $notes): void
    {
        DB::transaction(function () use ($draftId, $userId, $notes): void {
            ThemeVersionModel::query()->where('status', 'published')->get()->each->update(['status' => 'archived']);
            $draft = ThemeVersionModel::query()->where('status', 'draft')->findOrFail($draftId);
            $draft->forceFill(['status' => 'published', 'published_by' => $userId, 'published_at' => now(), 'notes' => $notes ?? $draft->notes])->save();
        });
    }

    /** @return array<string, mixed>|null */
    private function row(?ThemeVersionModel $m): ?array
    {
        if ($m === null) {
            return null;
        }
        $a = ['id' => $m->id, 'number' => (int) $m->number, 'status' => $m->status, 'name' => $m->name, 'notes' => $m->notes, 'derived_from' => $m->derived_from,
            'published_at' => $m->published_at === null ? null : CarbonImmutable::parse($m->published_at)->utc()->format('Y-m-d H:i:s'), 'updated_at' => CarbonImmutable::parse($m->updated_at)->utc()->format('Y-m-d H:i:s')];
        foreach (self::JSON as $k) {
            $a[$k] = (array) $m->{$k};
        }

        return $a;
    }
}
