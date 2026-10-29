<?php

declare(strict_types=1);

namespace Cronos\Media\Application;

use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Media\Domain\Ports\MediaStorage;
use DateTimeImmutable;

/** Physically removes the files of items that were soft-deleted long enough ago. The database row stays for the audit trail. */
final class PurgeDeletedMedia
{
    public function __construct(private readonly MediaRepository $media, private readonly MediaStorage $storage) {}

    /** @return int number of media items whose files were removed */
    public function __invoke(DateTimeImmutable $deletedBefore, int $batch = 200): int
    {
        $purged = 0;

        foreach ($this->media->deletedBefore($deletedBefore, $batch) as $item) {
            foreach ($item['paths'] as $path) {
                $this->storage->delete($item['disk'], $path);
            }
            $this->media->markPurged($item['id']);
            $purged++;
        }

        return $purged;
    }
}
