<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

interface TagRepository
{
    /** @return array{id: string, translations: array<string, array{name: string, slug: string}>}|null */
    public function find(string $id): ?array;

    /** @param array<string, array{name: string, slug: string}> $translations @return string new id */
    public function create(array $translations): string;

    /** @param array<string, array{name: string, slug: string}> $translations */
    public function update(string $id, array $translations): void;

    public function delete(string $id): void;

    public function slugTaken(string $locale, string $slug, ?string $exceptId = null): bool;
}
