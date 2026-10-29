<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure;

use Cronos\Catalog\Application\Listeners\PurgeCatalogCache;
use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\CatalogCache;
use Cronos\Catalog\Domain\Ports\CategoryRepository;
use Cronos\Catalog\Domain\Ports\PreviewTokenStore;
use Cronos\Catalog\Domain\Ports\ProductGallery;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Ports\StorefrontCatalog;
use Cronos\Catalog\Domain\Ports\TagRepository;
use Cronos\Catalog\Infrastructure\Adapters\DatabasePreviewTokenStore;
use Cronos\Catalog\Infrastructure\Adapters\MediaLibraryProductGallery;
use Cronos\Catalog\Infrastructure\Adapters\TaggedCatalogCache;
use Cronos\Catalog\Infrastructure\Persistence\CatalogObserver;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeValueModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\CategoryModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\TagModel;
use Cronos\Catalog\Infrastructure\Persistence\EloquentAttributeRepository;
use Cronos\Catalog\Infrastructure\Persistence\EloquentCategoryRepository;
use Cronos\Catalog\Infrastructure\Persistence\EloquentProductRepository;
use Cronos\Catalog\Infrastructure\Persistence\EloquentStorefrontCatalog;
use Cronos\Catalog\Infrastructure\Persistence\EloquentTagRepository;
use Cronos\Media\Domain\Events\MediaChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        CategoryRepository::class => EloquentCategoryRepository::class,
        TagRepository::class => EloquentTagRepository::class,
        AttributeRepository::class => EloquentAttributeRepository::class,
        ProductRepository::class => EloquentProductRepository::class,
        StorefrontCatalog::class => EloquentStorefrontCatalog::class,
        ProductGallery::class => MediaLibraryProductGallery::class,
        PreviewTokenStore::class => DatabasePreviewTokenStore::class,
        CatalogCache::class => TaggedCatalogCache::class,
    ];

    public function boot(): void
    {
        foreach ([ProductModel::class, CategoryModel::class, TagModel::class, AttributeModel::class, AttributeValueModel::class] as $model) {
            $model::observe(CatalogObserver::class);
        }

        Event::listen(CatalogEntityChanged::class, [PurgeCatalogCache::class, 'handle']);
        Event::listen(MediaChanged::class, [PurgeCatalogCache::class, 'handleMedia']);
    }
}
