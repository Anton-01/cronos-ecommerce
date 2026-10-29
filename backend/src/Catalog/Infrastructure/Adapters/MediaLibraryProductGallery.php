<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Adapters;

use Cronos\Catalog\Domain\Ports\ProductGallery;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use Cronos\Media\Application\Commands\AttachMedia;
use Cronos\Media\Application\Commands\DetachMedia;
use Cronos\Media\Application\Commands\ReorderMedia;
use Cronos\Media\Application\Handlers\AttachMediaHandler;
use Cronos\Media\Application\Handlers\DetachMediaHandler;
use Cronos\Media\Application\Handlers\ReorderMediaHandler;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;

/** Anti-corruption layer: the catalogue stores gallery photos in the Media Library. */
final class MediaLibraryProductGallery implements ProductGallery
{
    private const COLLECTION = 'gallery';

    public function __construct(
        private readonly AttachMediaHandler $attach,
        private readonly DetachMediaHandler $detach,
        private readonly ReorderMediaHandler $reorder,
    ) {}

    public function attach(string $productId, string $mediaId): void
    {
        ($this->attach)(new AttachMedia($mediaId, ProductModel::class, $productId, self::COLLECTION));
    }

    public function detach(string $productId, string $mediaId): void
    {
        $owns = MediaModel::query()->where(['id' => $mediaId, 'model_type' => ProductModel::class, 'model_id' => $productId, 'collection_name' => self::COLLECTION])->exists();
        $owns && ($this->detach)(new DetachMedia($mediaId));
    }

    public function reorder(string $productId, array $orderedMediaIds): void
    {
        ($this->reorder)(new ReorderMedia(ProductModel::class, $productId, self::COLLECTION, $orderedMediaIds));
    }

    public function isImage(string $mediaId): bool
    {
        return MediaModel::query()->whereKey($mediaId)->where('mime_type', 'like', 'image/%')->exists();
    }
}
