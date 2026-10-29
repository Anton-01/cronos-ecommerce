<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

/** The product photo gallery, kept in the Media Library. */
interface ProductGallery
{
    public function attach(string $productId, string $mediaId): void;

    public function detach(string $productId, string $mediaId): void;

    /** @param list<string> $orderedMediaIds */
    public function reorder(string $productId, array $orderedMediaIds): void;

    /** @return list<array{id: string, name: string, url: string, thumbUrl: ?string, status: string, alt: ?string}> in display order */
    public function list(string $productId): array;

    /** True when the media exists and is an image. */
    public function isImage(string $mediaId): bool;
}
