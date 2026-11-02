<?php

declare(strict_types=1);

namespace Cronos\Content\Interfaces\GraphQL;

use Cronos\Content\Application\PageService;
use Cronos\Content\Domain\Exceptions\ContentError;
use Cronos\Content\Domain\Ports\PageRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminPages
{
    public function __construct(private readonly PageRepository $pages, private readonly PageService $service) {}

    /** @param array<string, mixed> $args */
    public function list(mixed $root, array $args): array
    {
        return array_map($this->present(...), $this->pages->all($args['status'] ?? null, $args['search'] ?? null));
    }

    /** @param array<string, mixed> $args */
    public function show(mixed $root, array $args): ?array
    {
        $page = $this->pages->find($args['id']);

        return $page === null ? null : $this->present($page);
    }

    /** @param array<string, mixed> $args */
    public function create(mixed $root, array $args, GraphQLContext $context): array
    {
        $id = $this->service->create($args['key'], $args['markets'] ?? [], (int) ($args['position'] ?? 0), $this->translations($args['translations']), $this->uid($context));

        return $this->present($this->pages->find($id) ?? throw new ContentError('That page does not exist.', 'PAGE_NOT_FOUND'));
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->service->update($args['id'], $args['markets'] ?? null, $args['position'] ?? null, isset($args['translations']) ? $this->translations($args['translations']) : null, $this->uid($context));

        return $this->present($this->pages->find($args['id']) ?? throw new ContentError('That page does not exist.', 'PAGE_NOT_FOUND'));
    }

    /** @param array<string, mixed> $args */
    public function publish(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->service->publish($args['id'], $this->uid($context));

        return $this->present($this->pages->find($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function unpublish(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->service->unpublish($args['id'], $this->uid($context));

        return $this->present($this->pages->find($args['id']));
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args): bool
    {
        $this->service->delete($args['id']);

        return true;
    }

    /** @param list<array<string, mixed>> $list @return array<string, array<string, mixed>> */
    private function translations(array $list): array
    {
        $out = [];
        foreach ($list as $t) {
            $out[$t['locale']] = $t;
        }

        return $out;
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function present(array $p): array
    {
        return [
            'id' => $p['id'], 'key' => $p['key'], 'status' => $p['status'], 'markets' => $p['markets'], 'position' => $p['position'], 'publishedAt' => $p['published_at'], 'updatedAt' => $p['updated_at'],
            'translations' => array_map(static fn (array $t) => ['locale' => $t['locale'], 'slug' => $t['slug'], 'title' => $t['title'], 'bodyMd' => $t['body_md'], 'seoTitle' => $t['seo_title'], 'seoDescription' => $t['seo_description'], 'updatedAt' => $t['updated_at']], $p['translations']),
        ];
    }

    private function uid(GraphQLContext $context): ?string
    {
        $id = $context->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
