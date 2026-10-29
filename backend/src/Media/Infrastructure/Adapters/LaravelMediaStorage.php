<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Adapters;

use Cronos\Media\Domain\Ports\MediaStorage;
use Illuminate\Support\Facades\Storage;

final class LaravelMediaStorage implements MediaStorage
{
    public function put(string $disk, string $path, string $contents): void
    {
        Storage::disk($disk)->put($path, $contents);
    }

    public function get(string $disk, string $path): string
    {
        return Storage::disk($disk)->get($path) ?? throw new \RuntimeException("Media file [{$path}] is missing on disk [{$disk}].");
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    public function url(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }
}
