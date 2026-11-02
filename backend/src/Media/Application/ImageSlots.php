<?php

declare(strict_types=1);

namespace Cronos\Media\Application;

use Cronos\Media\Domain\Exceptions\InvalidImage;
use Cronos\Media\Domain\ValueObjects\ImageInfo;

/** The picture kinds of the shop and what each needs (config/images.php): minimums, ratios and the widths to build. */
final class ImageSlots
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('images.slots');
    }

    /** @return array<string, mixed>|null */
    public function find(?string $slot): ?array
    {
        return $slot === null ? null : ($this->all()[$slot] ?? null);
    }

    /** The slot a stored collection belongs to, for pictures uploaded without naming one (gallery → product). */
    public function forCollection(string $collection): ?string
    {
        foreach ($this->all() as $name => $spec) {
            if ($spec['collection'] === $collection) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Refuses a picture that is too small to ever look right, and lists softer problems (wrong shape, below the recommended size).
     *
     * @return list<string> warnings, in Spanish: they are shown to the person who uploads
     */
    public function check(string $slot, ImageInfo $info): array
    {
        $spec = $this->find($slot) ?? throw new InvalidImage("Unknown image slot [{$slot}].");
        [$minW, $minH] = $spec['min'];
        if ($info->width < $minW || $info->height < $minH) {
            throw new InvalidImage("La imagen mide {$info->width}×{$info->height} px y «{$spec['label']}» necesita al menos {$minW}×{$minH} px: se vería borrosa.");
        }

        $warnings = [];
        [$recW, $recH] = $spec['recommended'];
        if ($info->width < $recW * 0.75 || $info->height < $recH * 0.75) {
            $warnings[] = "Mide {$info->width}×{$info->height} px; lo ideal para «{$spec['label']}» es {$recW}×{$recH} px. En pantallas grandes puede verse suave.";
        }
        if ($spec['ratio'] !== null) {
            $ratio = $info->width / $info->height;
            if (abs($ratio - $spec['ratio']) / $spec['ratio'] > (float) config('images.ratio_tolerance')) {
                $warnings[] = 'La proporción es '.round($ratio, 2).':1 y «'.$spec['label'].'» usa '.round($spec['ratio'], 2).':1: se recortará'.($spec['focal'] ? ' (marca el punto focal para que no pierda lo importante)' : '').'.';
            }
        } elseif ($slot === 'logo') {
            $ratio = $info->width / $info->height;
            if ($ratio > 6) {
                $warnings[] = 'El logotipo es demasiado alargado (más de 6:1): en el encabezado se verá diminuto.';
            }
        }

        return $warnings;
    }

    /** Widths of the renditions to build for a collection, narrower than the original, plus the original's own (capped) size. @return list<int> */
    public function widths(string $collection, int $originalWidth): array
    {
        $slot = $this->forCollection($collection) ?? 'library';
        $configured = array_map('intval', (array) ($this->find($slot)['widths'] ?? config('media.responsive_widths')));
        $cap = min($originalWidth, (int) config('images.max_width'));
        $widths = array_values(array_filter($configured, fn (int $w) => $w > 0 && $w < $cap));
        $widths[] = $cap; // the original is never served: its optimised twin at full (capped) width stands in for it
        sort($widths);

        return array_values(array_unique($widths));
    }

    public function qualityFor(int $width): int
    {
        foreach ((array) config('images.quality_by_width') as $max => $quality) {
            if ($width <= (int) $max) {
                return (int) $quality;
            }
        }

        return (int) config('media.conversion_quality');
    }
}
