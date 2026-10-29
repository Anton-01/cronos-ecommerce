<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\ProcessMedia;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Media\Domain\Ports\MediaStorage;
use Cronos\Media\Domain\ValueObjects\Conversion;

/** Background step: extracts metadata and generates every configured rendition. */
final class ProcessMediaHandler
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $images,
    ) {}

    public function __invoke(ProcessMedia $command): void
    {
        $record = $this->media->find($command->id);

        // Deleted or already finished while the job waited in the queue: nothing to do (idempotent).
        if ($record === null || $record->status === 'ready') {
            return;
        }

        $this->media->markProcessing($record->id);

        if (! $record->isImage()) {
            $this->media->markReady($record->id, null, null, [], []);

            return;
        }

        $contents = $this->storage->get($record->disk, $record->path);
        $info = $this->images->inspect($contents);
        $properties = $this->images->metadata($contents);
        $conversions = [];

        foreach ($this->conversions() as $conversion) {
            $path = "{$record->id}/conversions/{$conversion->name}.{$conversion->format}";
            $rendered = $this->images->convert($contents, $conversion);
            $this->storage->put($record->disk, $path, $rendered->contents);
            $conversions[$conversion->name] = ['path' => $path, 'width' => $rendered->width, 'height' => $rendered->height];
        }

        $this->media->markReady($record->id, $info?->width, $info?->height, $properties, $conversions);
    }

    /** @return list<Conversion> */
    private function conversions(): array
    {
        $format = (string) config('media.conversion_format');
        $quality = (int) config('media.conversion_quality');

        return array_values(array_map(
            fn (string $name, array $c) => new Conversion($name, $c['width'], $c['height'], $c['fit'], $format, $quality),
            array_keys(config('media.conversions')),
            array_values(config('media.conversions')),
        ));
    }
}
