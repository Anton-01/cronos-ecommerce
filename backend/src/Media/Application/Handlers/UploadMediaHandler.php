<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\UploadMedia;
use Cronos\Media\Domain\Events\MediaUploaded;
use Cronos\Media\Domain\Exceptions\InvalidImage;
use Cronos\Media\Domain\Exceptions\MalwareDetected;
use Cronos\Media\Domain\Exceptions\MediaTooLarge;
use Cronos\Media\Domain\Exceptions\UnsupportedMediaType;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\Ports\MalwareScanner;
use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Media\Domain\Ports\MediaStorage;
use Cronos\Media\Domain\Ports\MimeSniffer;
use Cronos\Media\Domain\ValueObjects\MediaRecord;
use Cronos\Media\Domain\ValueObjects\NewMedia;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Str;

/**
 * Stores the original and returns immediately. Metadata and thumbnails are produced
 * later by a queued listener so the upload mutation stays fast.
 */
final class UploadMediaHandler
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly MimeSniffer $sniffer,
        private readonly ImageProcessor $images,
        private readonly MalwareScanner $scanner,
        private readonly DomainEventDispatcher $events,
    ) {}

    public function __invoke(UploadMedia $command): MediaRecord
    {
        $maxKb = $command->maxKb ?? (int) config('media.max_upload_kb');
        $size = (int) filesize($command->path);

        if ($size === 0 || $size > $maxKb * 1024) {
            throw new MediaTooLarge($maxKb);
        }

        $contents = (string) file_get_contents($command->path);

        // Trust the bytes, never the client-supplied MIME type or extension.
        $mime = $this->sniffer->sniff($contents);
        $allowed = config('media.allowed_mimes');

        if (! isset($allowed[$mime]) || ($command->onlyMimes !== null && ! in_array($mime, $command->onlyMimes, true))) {
            throw new UnsupportedMediaType;
        }

        // Scanned after the cheap checks (size, real type) so the daemon only sees plausible files.
        ($signature = $this->scanner->scan($contents)) === null || throw new MalwareDetected($signature);

        $info = null;
        if (str_starts_with($mime, 'image/')) {
            // Header-only read: rejects decompression bombs before any pixel is decoded.
            $info = $this->images->inspect($contents);

            if ($info === null || $info->pixels() > (int) config('media.max_pixels')) {
                throw new InvalidImage;
            }
        }

        $id = strtolower((string) Str::ulid());
        $disk = (string) config('media.disk');
        // The stored name is ours, never the client's: no path tricks, no collisions, no leaked names.
        $fileName = "{$id}.{$allowed[$mime]}";

        $this->storage->put($disk, "{$id}/{$fileName}", $contents);

        $record = $this->media->create(new NewMedia(
            id: $id,
            name: $this->displayName($command->clientName),
            fileName: $fileName,
            mimeType: $mime,
            disk: $disk,
            size: $size,
            checksum: hash('sha256', $contents),
            collection: $command->collection,
            uploadedBy: $command->uploadedBy,
            width: $info?->width,
            height: $info?->height,
        ));

        $this->events->dispatch(new MediaUploaded($record->id));

        return $record;
    }

    private function displayName(string $clientName): string
    {
        $name = pathinfo(basename(str_replace('\\', '/', $clientName)), PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        return Str::limit($name !== '' ? $name : 'file', 150, '');
    }
}
