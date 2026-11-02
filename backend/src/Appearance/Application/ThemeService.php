<?php

declare(strict_types=1);

namespace Cronos\Appearance\Application;

use Cronos\Appearance\Domain\Exceptions\ThemeError;
use Cronos\Appearance\Domain\Ports\ThemeMedia;
use Cronos\Appearance\Domain\Ports\ThemeRepository;
use Cronos\Appearance\Domain\Services\FeatureFlags;
use Cronos\Appearance\Domain\Services\HomeSchema;
use Cronos\Appearance\Domain\Services\NavigationSchema;
use Cronos\Appearance\Domain\Services\ThemePresets;
use Cronos\Appearance\Domain\Services\ThemeSchema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Edits the draft look of the storefront and switches it live. The live version is never edited: a change is prepared in the draft,
 * previewed through a short-lived link, then published, and the previous version stays one click away.
 */
final class ThemeService
{
    public const PREVIEW_MINUTES = 60;

    public function __construct(private readonly ThemeRepository $themes, private readonly ThemeMedia $media) {}

    /** @return list<string> */
    public function locales(): array
    {
        return array_values((array) config('app.supported_locales'));
    }

    /** @return array<string, mixed> what a theme holds when nothing was ever configured */
    public function defaults(): array
    {
        return [
            'tokens' => ThemeSchema::defaults(), 'assets' => [], 'navigation' => NavigationSchema::defaults(), 'home' => HomeSchema::defaults(), 'features' => FeatureFlags::defaults(),
        ];
    }

    /** The live version; before the first publish, the defaults (number 0) so a fresh install already has a storefront. @return array<string, mixed> */
    public function live(): array
    {
        return $this->themes->published() ?? ($this->defaults() + ['id' => null, 'number' => 0, 'status' => 'published', 'name' => 'Predeterminado', 'notes' => null, 'published_at' => null, 'updated_at' => null]);
    }

    /** The draft being edited, created from the live version the first time. @return array<string, mixed> */
    public function draft(?string $userId = null): array
    {
        $draft = $this->themes->draft();
        if ($draft !== null) {
            return $draft;
        }
        $live = $this->live();

        return $this->themes->createDraft($live, $live['id'], $userId, null);
    }

    public function hasUnpublishedChanges(): bool
    {
        $draft = $this->themes->draft();
        if ($draft === null) {
            return false;
        }
        $live = $this->live();

        return array_map(fn (string $k) => $draft[$k], ['tokens', 'assets', 'navigation', 'home', 'features']) !== array_map(fn (string $k) => $live[$k], ['tokens', 'assets', 'navigation', 'home', 'features']);
    }

    /**
     * Applies changes to the draft. Each group sent is merged onto what the draft already has and validated as a whole.
     *
     * @param  array{tokens?: array<string, mixed>, navigation?: array<string, mixed>, home?: list<array<string, mixed>>, features?: array<string, mixed>}  $changes
     * @return array<string, mixed> the draft
     */
    public function saveDraft(array $changes, ?string $userId): array
    {
        // Validate against the draft (or, before there is one, the live look) BEFORE creating anything: a refused save leaves no trace.
        $draft = $this->themes->draft() ?? $this->live();
        $update = [];

        if (isset($changes['tokens'])) {
            $merged = $draft['tokens'];
            foreach ((array) $changes['tokens'] as $group => $values) {
                $merged[$group] = is_array($values) ? array_replace((array) ($merged[$group] ?? []), $values) : $values;
            }
            $update['tokens'] = ThemeSchema::normalize($merged);
        }
        if (isset($changes['navigation'])) {
            $update['navigation'] = NavigationSchema::normalize(array_replace($draft['navigation'], (array) $changes['navigation']), $this->locales());
        }
        if (isset($changes['home'])) {
            $update['home'] = HomeSchema::normalize(array_values((array) $changes['home']), $this->locales());
            $this->assertMedia($update['home']);
        }
        if (isset($changes['features'])) {
            $update['features'] = FeatureFlags::normalize(array_replace($draft['features'], (array) $changes['features']));
        }
        if ($update !== []) {
            $this->themes->updateDraft($this->draft($userId)['id'], $update);
        }

        return $this->themes->draft() ?? $this->draft($userId);
    }

    /** Replaces the colours, type and shapes of the draft with a ready-made look (logos, menus and the home stay). @return array<string, mixed> */
    public function applyPreset(string $key, ?string $userId): array
    {
        $preset = ThemePresets::all()[$key] ?? throw new ThemeError('That preset does not exist.', 'UNKNOWN_PRESET');
        $draft = $this->draft($userId);
        $this->themes->updateDraft($draft['id'], ['tokens' => ThemeSchema::normalize($preset['tokens'])]);

        return $this->themes->draft();
    }

    /** @return array<string, mixed> */
    public function resetDraft(?string $userId): array
    {
        $draft = $this->draft($userId);
        $this->themes->updateDraft($draft['id'], ['tokens' => ThemeSchema::defaults(), 'navigation' => NavigationSchema::defaults(), 'home' => HomeSchema::defaults(), 'features' => FeatureFlags::defaults()]);

        return $this->themes->draft();
    }

    public function discardDraft(): void
    {
        $draft = $this->themes->draft();
        $draft === null || $this->themes->deleteDraft($draft['id']);
    }

    /** Brings an old version back as the draft (it is not live until published). @return array<string, mixed> */
    public function restore(string $versionId, ?string $userId): array
    {
        $version = $this->themes->find($versionId) ?? throw new ThemeError('That version does not exist.', 'VERSION_NOT_FOUND');
        $this->themes->atomically(function () use ($version, $userId): void {
            $this->discardDraft();
            $this->themes->createDraft($version, $version['id'], $userId, $version['name']);
        });

        return $this->themes->draft();
    }

    /** @return array<string, mixed> the live version */
    public function publish(?string $notes, ?string $userId): array
    {
        $draft = $this->themes->draft() ?? throw new ThemeError('There is nothing to publish: change something first.', 'NO_DRAFT');
        $this->hasUnpublishedChanges() || throw new ThemeError('The draft is identical to the live version.', 'NOTHING_TO_PUBLISH');
        $this->assertMedia($draft['home']);
        $this->themes->publish($draft['id'], $userId, $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 500));
        Log::info('Theme published', ['version' => $draft['number'], 'by' => $userId]);

        return $this->live();
    }

    /** A link that shows the draft on the real storefront for a while, to review it before it goes live. */
    public function previewToken(): string
    {
        return Crypt::encryptString(json_encode(['t' => 'theme-preview', 'exp' => now()->getTimestamp() + self::PREVIEW_MINUTES * 60], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null the draft when the token is valid and fresh */
    public function draftForToken(string $token): ?array
    {
        try {
            $p = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($p) && ($p['t'] ?? null) === 'theme-preview' && ($p['exp'] ?? 0) >= now()->getTimestamp() ? $this->themes->draft() : null;
    }

    /** @return list<array<string, mixed>> */
    public function versions(int $limit = 30): array
    {
        return $this->themes->versions($limit);
    }

    /** Every picture a home section points to must be a real, ready picture. @param list<array<string, mixed>> $home */
    private function assertMedia(array $home): void
    {
        $ids = [];
        array_walk_recursive($home, function ($value, $key) use (&$ids): void {
            in_array($key, ['image', 'imageMobile'], true) && is_string($value) && $value !== '' && $ids[$value] = true;
        });
        if ($ids === []) {
            return;
        }
        $found = $this->media->describe(array_keys($ids));
        $missing = array_diff(array_keys($ids), array_keys($found));
        $missing === [] || throw new ThemeError('Una imagen de la portada ya no existe en la biblioteca.', 'MEDIA_NOT_FOUND', ['home' => 'Imagen no encontrada: '.implode(', ', $missing)]);
    }
}
