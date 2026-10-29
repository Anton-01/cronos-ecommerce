<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Ports;

use Cronos\Media\Domain\ValueObjects\MediaRecord;
use Cronos\Media\Domain\ValueObjects\NewMedia;

interface MediaRepository
{
    public function create(NewMedia $media): MediaRecord;

    public function find(string $id): ?MediaRecord;

    public function markProcessing(string $id): void;

    /** @param array<string, mixed> $properties @param array<string, array{path: string, width: int, height: int}> $conversions */
    public function markReady(string $id, ?int $width, ?int $height, array $properties, array $conversions): void;

    public function markFailed(string $id, string $reason): void;

    public function rename(string $id, string $name): void;

    /** @param array<string, array{alt?: ?string, title?: ?string, caption?: ?string}> $translations keyed by locale */
    public function syncTranslations(string $id, array $translations): void;

    /** Moves the file into an owner's collection; single-file collections drop the previous item. */
    public function attach(string $id, string $ownerType, string $ownerId, string $collection, bool $replaceExisting): void;

    /** Returns the file to the library (no owner), keeping it available for reuse. */
    public function detach(string $id): void;

    /** @param list<string> $orderedIds position follows the list order */
    public function reorder(string $ownerType, string $ownerId, string $collection, array $orderedIds): void;

    /** @return array{model_type: ?string, model_id: ?string, collection_name: string}|null */
    public function ownerOf(string $id): ?array;

    /** Soft delete. Files stay on disk until the purge job removes them. */
    public function delete(string $id): void;
}
