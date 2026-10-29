<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Mutations;

use Cronos\Catalog\Application\AttributeManager;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeValueModel;
use Cronos\Catalog\Interfaces\GraphQL\Convert;
use Cronos\Catalog\Interfaces\GraphQL\Queries\AdminCatalogQuery;

final class AttributeMutations
{
    public function __construct(private readonly AttributeManager $manager, private readonly AdminCatalogQuery $query) {}

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args): array
    {
        $id = $this->manager->create($args['code'], $args['type'] ?? 'select', (bool) ($args['isFilterable'] ?? true), (int) ($args['position'] ?? 0), Convert::texts($args['names']));

        return $this->present($id);
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args): array
    {
        $attributes = Convert::snake(array_intersect_key($args, array_flip(['type', 'isFilterable', 'position'])));
        $this->manager->update($args['id'], $attributes, isset($args['names']) ? Convert::texts($args['names']) : null);

        return $this->present($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): bool
    {
        $this->manager->delete($args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function addValue(mixed $root, array $args): array
    {
        $this->manager->addValue($args['attributeId'], $args['code'], $args['meta'] ?? null, (int) ($args['position'] ?? 0), Convert::texts($args['labels']));

        return $this->present($args['attributeId']);
    }

    /** @param array<string, mixed> $args */
    public function updateValue(mixed $root, array $args): array
    {
        $attributes = array_intersect_key($args, array_flip(['meta', 'position']));
        $this->manager->updateValue($args['id'], $attributes, isset($args['labels']) ? Convert::texts($args['labels']) : null);

        return $this->present(AttributeValueModel::query()->findOrFail($args['id'])->attribute_id);
    }

    /** @param array<string, mixed> $args */
    public function deleteValue(mixed $root, array $args): bool
    {
        $this->manager->deleteValue($args['id']);

        return true;
    }

    /** @return array<string, mixed> */
    private function present(string $attributeId): array
    {
        return $this->query->attribute(AttributeModel::query()->with(['translations', 'values.translations'])->findOrFail($attributeId));
    }
}
