<?php

declare(strict_types=1);

namespace Cronos\Content\Domain\Ports;

interface PageRepository
{
    /** @return list<array<string, mixed>> pages with their translations, for the back office */
    public function all(?string $status, ?string $search): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    public function keyExists(string $key, ?string $exceptId): bool;

    public function slugTaken(string $locale, string $slug, ?string $exceptPageId): bool;

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @param  list<string>  $markets
     */
    public function create(string $key, array $markets, int $position, array $translations, ?string $userId): string;

    /**
     * @param  array<string, array<string, mixed>>|null  $translations
     * @param  list<string>|null  $markets
     */
    public function update(string $id, ?array $markets, ?int $position, ?array $translations, ?string $userId): void;

    public function setStatus(string $id, string $status, ?string $userId): void;

    public function delete(string $id): void;

    /** @return array<string, mixed>|null the published page with the translation in that language, by its address */
    public function publishedBySlug(string $locale, string $slug): ?array;

    /** @return list<array{locale: string, slug: string}> */
    public function alternates(string $pageId): array;

    /** @return list<array<string, mixed>> published pages in a language, in order */
    public function published(string $locale): array;
}
