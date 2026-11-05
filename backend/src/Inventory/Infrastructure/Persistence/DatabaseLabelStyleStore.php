<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\LabelStyleStore;
use Illuminate\Support\Facades\DB;

final class DatabaseLabelStyleStore implements LabelStyleStore
{
    public function current(): ?array
    {
        $json = DB::table('warehouse_label_style_versions')->orderByDesc('id')->value('style');

        return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function save(array $style, ?string $userId): void
    {
        DB::table('warehouse_label_style_versions')->insert(['style' => json_encode($style, JSON_THROW_ON_ERROR), 'changed_by' => $userId, 'changed_at' => now()]);
    }

    public function history(int $limit): array
    {
        return DB::table('warehouse_label_style_versions')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($r) => ['style' => json_decode($r->style, true), 'changed_by' => $r->changed_by, 'changed_at' => (string) $r->changed_at])->all();
    }
}
