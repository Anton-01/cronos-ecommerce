<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\ProductGallery;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Shared\Domain\DomainEventDispatcher;

final class GalleryManager
{
    public const MAX_IMAGES = 20;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductGallery $gallery,
        private readonly DomainEventDispatcher $events,
    ) {}

    public function attach(string $productId, string $mediaId): void
    {
        $this->products->find($productId) ?? throw new NotFound('The product');
        $this->gallery->isImage($mediaId) || throw new InvalidProduct('Only images can be added to the gallery.');

        $this->gallery->attach($productId, $mediaId);
        $this->events->dispatch(new CatalogEntityChanged('product', $productId));
    }

    public function detach(string $productId, string $mediaId): void
    {
        $this->products->find($productId) ?? throw new NotFound('The product');

        $this->gallery->detach($productId, $mediaId);
        $this->events->dispatch(new CatalogEntityChanged('product', $productId));
    }

    /** @param list<string> $orderedMediaIds */
    public function reorder(string $productId, array $orderedMediaIds): void
    {
        $this->products->find($productId) ?? throw new NotFound('The product');

        $this->gallery->reorder($productId, array_values(array_unique($orderedMediaIds)));
        $this->events->dispatch(new CatalogEntityChanged('product', $productId));
    }
}
