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

    /** True when the media exists and is an image. */
    public function isImage(string $mediaId): bool;
}
