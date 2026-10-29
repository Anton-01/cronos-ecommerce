<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Mutations;

use Cronos\Catalog\Application\TagManager;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\TagModel;
use Cronos\Catalog\Interfaces\GraphQL\Convert;
use Cronos\Catalog\Interfaces\GraphQL\Queries\AdminCatalogQuery;

final class TagMutations
{
    public function __construct(private readonly TagManager $manager) {}

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args): array
    {
        $id = $this->manager->create(Convert::translations($args['translations']));

        return AdminCatalogQuery::tag(TagModel::query()->with('translations')->findOrFail($id));
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args): array
    {
        $this->manager->update($args['id'], Convert::translations($args['translations']));

        return AdminCatalogQuery::tag(TagModel::query()->with('translations')->findOrFail($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): bool
    {
        $this->manager->delete($args['id']);

        return true;
    }
}
