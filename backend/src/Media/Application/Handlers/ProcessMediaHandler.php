<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\ProcessMedia;
use Cronos\Media\Application\ImageSlots;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Media\Domain\Ports\MediaStorage;
use Cronos\Media\Domain\ValueObjects\Conversion;

/**
 * Background step: extracts metadata and builds every rendition the storefront will ever serve.
 *
 * The goal is the lightest file that still looks right: every width is encoded as WebP (and AVIF when this server can), with a quality
 * that drops for small sizes, lossless only for flat graphics with transparency, EXIF/GPS stripped, and never larger than 2560 px.
 * The uploaded original is only the master copy: it is not part of the srcset, a full-size optimised twin stands in for it.
 */
final class ProcessMediaHandler
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $images,
        private readonly ImageSlots $slots,
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
        $focal = $record->properties['focal'] ?? ['x' => 0.5, 'y' => 0.5];
        [$fx, $fy] = [(float) ($focal['x'] ?? 0.5), (float) ($focal['y'] ?? 0.5)];
        $format = (string) config('media.conversion_format');
        // Flat graphics with transparency (logos, icons) keep their exact pixels; photos take the lossy encoder.
        $lossless = $this->images->hasAlpha($contents) && ($info === null || $info->pixels() <= 1_000_000);

        $conversions = [];
        foreach ($this->conversions() as $c) {
            $conversion = new Conversion($c->name, $c->width, $c->height, $c->fit, $c->format, $c->quality, $fx, $fy, $lossless);
            $path = "{$record->id}/conversions/{$conversion->name}.{$conversion->format}";
            $rendered = $this->images->convert($contents, $conversion);
            $this->storage->put($record->disk, $path, $rendered->contents);
            $conversions[$conversion->name] = ['path' => $path, 'width' => $rendered->width, 'height' => $rendered->height, 'bytes' => strlen($rendered->contents)];
        }

        $responsive = [];
        if ($info !== null && $info->width > 0) {
            foreach ($this->slots->widths($record->collection, $info->width) as $width) {
                $height = max(1, (int) ceil($width * $info->height / $info->width));
                foreach ($this->formats() as $fmt) {
                    $quality = $fmt === 'avif' ? (int) config('images.avif_quality') : $this->slots->qualityFor($width);
                    $rendered = $this->images->convert($contents, new Conversion("w{$width}", $width, $height, 'contain', $fmt, $quality, $fx, $fy, $lossless && $fmt === 'webp'));
                    $name = $fmt === $format ? "w{$width}" : "w{$width}@{$fmt}";
                    $path = "{$record->id}/responsive/w{$width}.{$fmt}";
                    $this->storage->put($record->disk, $path, $rendered->contents);
                    $responsive[$name] = ['path' => $path, 'width' => $rendered->width, 'height' => $rendered->height, 'bytes' => strlen($rendered->contents), 'format' => $fmt];
                }
            }
        }

        $properties['optimization'] = $this->summary(strlen($contents), $responsive);
        $this->media->markReady($record->id, $info?->width, $info?->height, $properties, $conversions, $responsive);
    }

    /** What the visitor downloads compared with what was uploaded (largest rendition in the best format). @param array<string, array<string, mixed>> $responsive @return array<string, int|float> */
    private function summary(int $original, array $responsive): array
    {
        $widest = array_filter($responsive, fn (array $r, string $k) => ! str_contains($k, '@'), ARRAY_FILTER_USE_BOTH);
        if ($widest === []) {
            return [];
        }
        uasort($widest, fn (array $a, array $b) => $b['width'] <=> $a['width']);
        $best = (int) reset($widest)['bytes'];

        return ['original_bytes' => $original, 'served_bytes' => $best, 'saved_percent' => $original > 0 ? round(max(0, 100 - $best * 100 / $original), 1) : 0.0];
    }

    /** @return list<string> */
    private function formats(): array
    {
        $formats = [(string) config('media.conversion_format')];
        $avif = config('images.avif', 'auto');
        if ($avif !== false && $avif !== 'false' && $avif !== '0' && $this->images->canEncode('avif')) {
            $formats[] = 'avif';
        }

        return $formats;
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
