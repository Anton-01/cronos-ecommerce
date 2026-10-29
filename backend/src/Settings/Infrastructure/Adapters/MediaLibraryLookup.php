<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure\Adapters;

use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Cronos\Settings\Domain\Ports\MediaLookup;

/** Anti-corruption layer towards the Media module. */
final class MediaLibraryLookup implements MediaLookup
{
    public function isUsableImage(string $mediaId): bool
    {
        return MediaModel::query()->whereKey($mediaId)->where('mime_type', 'like', 'image/%')->exists();
    }

    public function urls(array $ids): array
    {
        return MediaModel::query()->whereKey($ids)->get()->mapWithKeys(fn (MediaModel $m) => [$m->id => $m->url])->all();
    }
}
