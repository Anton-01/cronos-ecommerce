<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Mutations;

use Cronos\Catalog\Application\AdminProductReader;
use Cronos\Catalog\Application\VariationManager;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Interfaces\GraphQL\Convert;

final class VariationMutations
{
    public function __construct(private readonly VariationManager $manager, private readonly AdminProductReader $reader, private readonly ProductRepository $products) {}

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args): array
    {
        $this->manager->create($args['parentId'], $this->input($args['input']));

        return $this->reader->product($args['parentId']);
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args): array
    {
        $parentId = $this->products->find($args['id'])['parent_id'] ?? null;
        $this->manager->update($args['id'], $this->input($args['input']));

        return $this->reader->product($parentId ?? $args['id']);
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): array
    {
        $parentId = $this->products->find($args['id'])['parent_id'] ?? null;
        $this->manager->delete($args['id']);

        return $this->reader->product($parentId);
    }

    /** @param array<string, mixed> $args */
    public function generate(mixed $root, array $args): array
    {
        $this->manager->generate($args['parentId']);

        return $this->reader->product($args['parentId']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function input(array $input): array
    {
        $out = Convert::snake(array_diff_key($input, ['dimensions' => 1]));
        array_key_exists('dimensions', $input) && $out['dimensions'] = $input['dimensions'] === null ? null : Convert::snake($input['dimensions']);

        return $out;
    }
}
