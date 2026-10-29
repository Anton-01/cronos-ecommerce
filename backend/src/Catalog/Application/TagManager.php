<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Exceptions\SlugTaken;
use Cronos\Catalog\Domain\Ports\TagRepository;

final class TagManager
{
    public function __construct(private readonly TagRepository $tags) {}

    /** @param array<string, array<string, mixed>> $translations */
    public function create(array $translations): string
    {
        $normalised = Localized::normalise($translations);
        $this->assertSlugsFree($normalised);

        return $this->tags->create($normalised);
    }

    /** @param array<string, array<string, mixed>> $translations */
    public function update(string $id, array $translations): void
    {
        $this->tags->find($id) ?? throw new NotFound('The tag');
        $normalised = Localized::normalise($translations);
        $this->assertSlugsFree($normalised, $id);

        $this->tags->update($id, $normalised);
    }

    public function delete(string $id): void
    {
        $this->tags->find($id) ?? throw new NotFound('The tag');

        $this->tags->delete($id);
    }

    /** @param array<string, array<string, ?string>> $translations */
    private function assertSlugsFree(array $translations, ?string $exceptId = null): void
    {
        foreach ($translations as $locale => $t) {
            ! $this->tags->slugTaken($locale, $t['slug'], $exceptId) || throw new SlugTaken($t['slug'], $locale);
        }
    }
}
