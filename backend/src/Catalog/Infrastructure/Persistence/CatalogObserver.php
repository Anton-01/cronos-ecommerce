<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeValueModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\CategoryModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\TagModel;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Database\Eloquent\Model;

/** Safety net: any Eloquent write (tinker, seeders, imports) purges the catalogue cache, not just our handlers. */
final class CatalogObserver
{
    public function __construct(private readonly DomainEventDispatcher $events) {}

    public function saved(Model $model): void
    {
        $this->purge($model);
    }

    public function deleted(Model $model): void
    {
        $this->purge($model);
    }

    public function restored(Model $model): void
    {
        $this->purge($model);
    }

    private function purge(Model $model): void
    {
        $type = match (true) {
            $model instanceof ProductModel => 'product',
            $model instanceof CategoryModel => 'category',
            $model instanceof TagModel => 'tag',
            $model instanceof AttributeModel, $model instanceof AttributeValueModel => 'attribute',
            default => null,
        };

        $type === null || $this->events->dispatch(new CatalogEntityChanged($type, (string) $model->getKey()));
    }
}
