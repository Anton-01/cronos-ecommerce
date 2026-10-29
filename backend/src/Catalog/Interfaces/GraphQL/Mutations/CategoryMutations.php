<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Mutations;

use Cronos\Catalog\Application\CategoryManager;
use Cronos\Catalog\Domain\Ports\CategoryRepository;
use Cronos\Catalog\Interfaces\GraphQL\Convert;
use Cronos\Catalog\Interfaces\GraphQL\Queries\CategoryPresenter;

final class CategoryMutations
{
    public function __construct(private readonly CategoryManager $manager, private readonly CategoryRepository $repo) {}

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args): array
    {
        $id = $this->manager->create([
            'parent_id' => $args['parentId'] ?? null, 'position' => $args['position'] ?? 0, 'is_active' => $args['isActive'] ?? true,
            'image_media_id' => $args['imageMediaId'] ?? null, 'default_tax_class_id' => $args['taxClassId'] ?? null, 'translations' => Convert::translations($args['translations']),
        ]);

        return CategoryPresenter::present($this->repo->find($id));
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args): array
    {
        $input = Convert::snake(array_diff_key($args, ['id' => 1, 'translations' => 1, 'taxClassId' => 1]));
        array_key_exists('taxClassId', $args) && $input['default_tax_class_id'] = $args['taxClassId'];
        isset($args['translations']) && $input['translations'] = Convert::translations($args['translations']);
        $this->manager->update($args['id'], $input);

        return CategoryPresenter::present($this->repo->find($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function move(mixed $root, array $args): array
    {
        $this->manager->move($args['id'], $args['parentId'] ?? null, (int) ($args['position'] ?? 0));

        return CategoryPresenter::present($this->repo->find($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): bool
    {
        $this->manager->delete($args['id']);

        return true;
    }
}
