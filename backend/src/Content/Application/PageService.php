<?php

declare(strict_types=1);

namespace Cronos\Content\Application;

use Cronos\Content\Domain\Exceptions\ContentError;
use Cronos\Content\Domain\Ports\PageRepository;
use Cronos\Content\Domain\Services\PageRules;
use Cronos\Shared\Domain\StorefrontCache;
use Illuminate\Support\Facades\Cache;

/** Writing side of the static pages. Every change bumps one version number (the cache key of everything public) and tells the storefront. */
final class PageService
{
    public const VERSION_KEY = 'content:version';

    public function __construct(private readonly PageRepository $pages, private readonly StorefrontCache $storefront) {}

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_values((array) config('app.supported_locales'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @param  list<string>  $markets
     */
    public function create(string $key, array $markets, int $position, array $translations, ?string $userId): string
    {
        $key = PageRules::assertKey($key);
        ! $this->pages->keyExists($key, null) || throw new ContentError('A page with that key already exists.', 'PAGE_KEY_TAKEN', ['key' => 'Ya existe una página con esa clave.']);
        $t = $this->checked(null, PageRules::normalizeTranslations($translations, $this->locales()));
        $id = $this->pages->create($key, PageRules::normalizeMarkets($markets), max(0, $position), $t, $userId);
        $this->changed();

        return $id;
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $translations
     * @param  list<string>|null  $markets
     */
    public function update(string $id, ?array $markets, ?int $position, ?array $translations, ?string $userId): void
    {
        $this->pages->find($id) ?? throw new ContentError('That page does not exist.', 'PAGE_NOT_FOUND');
        $t = $translations === null ? null : $this->checked($id, PageRules::normalizeTranslations($translations, $this->locales()));
        $this->pages->update($id, $markets === null ? null : PageRules::normalizeMarkets($markets), $position === null ? null : max(0, $position), $t, $userId);
        $this->changed();
    }

    public function publish(string $id, ?string $userId): void
    {
        $page = $this->pages->find($id) ?? throw new ContentError('That page does not exist.', 'PAGE_NOT_FOUND');
        $page['translations'] !== [] || throw new ContentError('Write the page before publishing it.', 'INVALID_PAGE');
        $this->pages->setStatus($id, 'published', $userId);
        $this->changed();
    }

    public function unpublish(string $id, ?string $userId): void
    {
        $this->pages->find($id) ?? throw new ContentError('That page does not exist.', 'PAGE_NOT_FOUND');
        $this->pages->setStatus($id, 'draft', $userId);
        $this->changed();
    }

    public function delete(string $id): void
    {
        $this->pages->find($id) ?? throw new ContentError('That page does not exist.', 'PAGE_NOT_FOUND');
        $this->pages->delete($id);
        $this->changed();
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    private function checked(?string $pageId, array $translations): array
    {
        $errors = [];
        foreach ($translations as $locale => $t) {
            $this->pages->slugTaken($locale, $t['slug'], $pageId) && $errors["translations.{$locale}.slug"] = "La dirección «{$t['slug']}» ya la usa otra página.";
        }
        $errors === [] || throw new ContentError('That address is already used by another page.', 'SLUG_TAKEN', $errors);

        return $translations;
    }

    private function changed(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
        $this->storefront->invalidate('content');
    }
}
