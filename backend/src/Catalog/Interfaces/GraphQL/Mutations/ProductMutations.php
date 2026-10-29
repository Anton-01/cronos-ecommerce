<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Mutations;

use Cronos\Catalog\Application\AdminProductReader;
use Cronos\Catalog\Application\GalleryManager;
use Cronos\Catalog\Application\ProductManager;
use Cronos\Catalog\Interfaces\GraphQL\Convert;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ProductMutations
{
    public function __construct(private readonly ProductManager $manager, private readonly GalleryManager $gallery, private readonly AdminProductReader $reader) {}

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args): array
    {
        return $this->reader->product($this->manager->create($this->input($args['input'])));
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args): array
    {
        $this->manager->update($args['id'], $this->input($args['input']));

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): bool
    {
        $this->manager->delete($args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function setAttributes(mixed $root, array $args): array
    {
        $this->manager->setAttributes($args['id'], array_map(fn (array $a) => Convert::snake($a), $args['attributes']));

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function setOfferedValues(mixed $root, array $args): array
    {
        $this->manager->setOfferedValues($args['id'], $args['valueIds']);

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function setGroupItems(mixed $root, array $args): array
    {
        $this->manager->setGroupItems($args['id'], array_map(fn (array $i) => ['product_id' => $i['productId'], 'default_quantity' => $i['defaultQuantity'] ?? 1], $args['items']));

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function attachMedia(mixed $root, array $args): array
    {
        $this->gallery->attach($args['id'], $args['mediaId']);

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function detachMedia(mixed $root, array $args): array
    {
        $this->gallery->detach($args['id'], $args['mediaId']);

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function reorderMedia(mixed $root, array $args): array
    {
        $this->gallery->reorder($args['id'], $args['mediaIds']);

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function publish(mixed $root, array $args): array
    {
        $this->manager->publish($args['id']);

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args */
    public function unpublish(mixed $root, array $args): array
    {
        $this->manager->unpublish($args['id'], $args['status'] ?? 'inactive');

        return $this->reader->product($args['id']);
    }

    /** @param array<string, mixed> $args @return array{token: string, expiresAt: \DateTimeInterface, path: string} */
    public function issuePreview(mixed $root, array $args, GraphQLContext $context): array
    {
        $ttl = max(5, min((int) ($args['ttlMinutes'] ?? 120), 1440));
        $token = $this->manager->issuePreview($args['id'], $ttl, (string) $context->user()->getAuthIdentifier());

        return ['token' => $token, 'expiresAt' => now()->addMinutes($ttl), 'path' => '/preview/'.$token];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function input(array $input): array
    {
        $out = Convert::snake(array_diff_key($input, ['translations' => 1, 'categoryIds' => 1, 'primaryCategoryId' => 1, 'tagIds' => 1, 'sales' => 1, 'dimensions' => 1]));
        array_key_exists('translations', $input) && $out['translations'] = Convert::translations($input['translations']);
        array_key_exists('categoryIds', $input) && $out['category_ids'] = $input['categoryIds'] ?? [];
        array_key_exists('primaryCategoryId', $input) && $out['primary_category_id'] = $input['primaryCategoryId'];
        array_key_exists('tagIds', $input) && $out['tag_ids'] = $input['tagIds'] ?? [];
        array_key_exists('dimensions', $input) && $out['dimensions'] = $input['dimensions'] === null ? null : Convert::snake($input['dimensions']);
        if (array_key_exists('sales', $input) && $input['sales'] !== null) {
            $out['sales'] = Convert::snake($input['sales']);
        }

        return $out;
    }
}
