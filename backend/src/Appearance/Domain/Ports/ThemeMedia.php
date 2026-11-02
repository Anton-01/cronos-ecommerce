<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Ports;

/** The picture library as the theme sees it: does it exist, what does it look like, and its original bytes for re-cropping. */
interface ThemeMedia
{
    /** @param list<string> $ids @return array<string, array{id: string, mime: string, width: ?int, height: ?int, url: string, srcset: ?string, srcsetAvif: ?string, alt: array<string, string>, focal: ?array{x: float, y: float}, status: string}> by id, images only */
    public function describe(array $ids): array;

    /** @return array{contents: string, mime: string, width: int, height: int}|null the uploaded original */
    public function original(string $id): ?array;

    /** Stores a derived file and returns its public address. */
    public function store(string $path, string $contents): string;

    public function remove(string $path): void;
}
