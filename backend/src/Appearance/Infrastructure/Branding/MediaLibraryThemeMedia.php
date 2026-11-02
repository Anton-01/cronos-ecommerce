<?php

declare(strict_types=1);

namespace Cronos\Appearance\Infrastructure\Branding;

use Cronos\Appearance\Domain\Ports\ThemeMedia;
use Cronos\Media\Domain\Ports\MediaStorage;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;

final class MediaLibraryThemeMedia implements ThemeMedia
{
    public function __construct(private readonly MediaStorage $storage) {}

    public function describe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (MediaModel::query()->with('translations')->whereIn('id', $ids)->where('mime_type', 'like', 'image/%')->get() as $m) {
            $out[$m->id] = [
                'id' => $m->id, 'mime' => $m->mime_type, 'width' => $m->width, 'height' => $m->height, 'url' => $m->url, 'srcset' => $m->srcset, 'srcsetAvif' => $m->srcset_avif,
                'alt' => $m->translations->mapWithKeys(fn ($t) => [$t->locale => (string) $t->alt])->filter()->all(), 'focal' => $m->focal, 'status' => $m->status,
            ];
        }

        return $out;
    }

    public function original(string $id): ?array
    {
        $m = MediaModel::query()->where('id', $id)->where('mime_type', 'like', 'image/%')->first();
        if ($m === null || $m->width === null || $m->height === null) {
            return null;
        }

        return ['contents' => $this->storage->get($m->disk, $m->id.'/'.$m->file_name), 'mime' => $m->mime_type, 'width' => (int) $m->width, 'height' => (int) $m->height];
    }

    public function store(string $path, string $contents): string
    {
        $disk = (string) config('media.disk');
        $this->storage->put($disk, $path, $contents);

        return $this->storage->url($disk, $path);
    }

    public function remove(string $path): void
    {
        $this->storage->delete((string) config('media.disk'), $path);
    }
}
