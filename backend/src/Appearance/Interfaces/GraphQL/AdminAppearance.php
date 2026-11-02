<?php

declare(strict_types=1);

namespace Cronos\Appearance\Interfaces\GraphQL;

use Cronos\Appearance\Application\BrandingAssets;
use Cronos\Appearance\Application\ThemePresenter;
use Cronos\Appearance\Application\ThemeService;
use Cronos\Appearance\Domain\Exceptions\ThemeError;
use Cronos\Appearance\Domain\Services\FeatureFlags;
use Cronos\Appearance\Domain\Services\HomeSchema;
use Cronos\Appearance\Domain\Services\ThemePresets;
use Cronos\Appearance\Domain\Services\ThemeSchema;
use Cronos\Media\Application\Commands\UploadMedia;
use Cronos\Media\Application\Handlers\UploadMediaHandler;
use Illuminate\Http\UploadedFile;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminAppearance
{
    private const MEDIA_SLOT = ['logo' => 'logo', 'logoDark' => 'logo', 'logoMobile' => 'logo', 'favicon' => 'favicon', 'og' => 'og'];

    public function __construct(
        private readonly ThemeService $themes,
        private readonly ThemePresenter $presenter,
        private readonly BrandingAssets $branding,
        private readonly UploadMediaHandler $uploads,
    ) {}

    public function state(): array
    {
        return [
            'draft' => $this->presenter->admin($this->themes->draft()), 'live' => $this->presenter->admin($this->themes->live()), 'hasUnpublishedChanges' => $this->themes->hasUnpublishedChanges(),
            'versions' => array_map(static fn (array $v) => ['id' => $v['id'], 'number' => $v['number'], 'status' => $v['status'], 'name' => $v['name'], 'notes' => $v['notes'], 'createdAt' => $v['created_at'], 'publishedAt' => $v['published_at']], $this->themes->versions()),
            'presets' => array_map(static fn (string $k, array $p) => ['key' => $k, 'label' => $p['label'], 'description' => $p['description'], 'tokens' => ThemeSchema::normalize($p['tokens'])], array_keys(ThemePresets::all()), array_values(ThemePresets::all())),
            'fonts' => array_map(static fn (string $f, array $d) => ['family' => $f, 'label' => $d['label'], 'weights' => $d['weights']], array_keys(ThemeSchema::FONTS), array_values(ThemeSchema::FONTS)),
            'features' => [
                ...array_map(static fn (string $k, array $f) => ['key' => $k, 'label' => $f['label'], 'reserved' => false], array_keys(FeatureFlags::AVAILABLE), array_values(FeatureFlags::AVAILABLE)),
                ...array_map(static fn (string $k, string $l) => ['key' => $k, 'label' => $l, 'reserved' => true], array_keys(FeatureFlags::RESERVED), array_values(FeatureFlags::RESERVED)),
            ],
            'homeSectionTypes' => [...array_map(static fn (string $t) => ['type' => $t, 'reserved' => false], HomeSchema::TYPES), ...array_map(static fn (string $t) => ['type' => $t, 'reserved' => true], HomeSchema::RESERVED)],
        ];
    }

    /** @param array<string, mixed> $args */
    public function save(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->presenter->admin($this->themes->saveDraft(array_intersect_key($args, array_flip(['tokens', 'navigation', 'home', 'features'])), $this->uid($context)));
    }

    /** @param array<string, mixed> $args */
    public function preset(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->presenter->admin($this->themes->applyPreset($args['key'], $this->uid($context)));
    }

    public function reset(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->presenter->admin($this->themes->resetDraft($this->uid($context)));
    }

    public function discard(): bool
    {
        $this->themes->discardDraft();

        return true;
    }

    /** @param array<string, mixed> $args */
    public function restore(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->presenter->admin($this->themes->restore($args['id'], $this->uid($context)));
    }

    /** @param array<string, mixed> $args */
    public function publish(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->presenter->admin($this->themes->publish($args['notes'] ?? null, $this->uid($context)));
    }

    public function previewLink(): string
    {
        return rtrim((string) config('app.storefront_url'), '/').'/?theme_preview='.rawurlencode($this->themes->previewToken());
    }

    /** @param array<string, mixed> $args */
    public function upload(mixed $root, array $args, GraphQLContext $context): array
    {
        /** @var UploadedFile $file */
        $file = $args['file'];
        ($file->isValid() && $file->getRealPath() !== false) || throw new ThemeError('La carga falló o se interrumpió.', 'UPLOAD_FAILED');
        $slot = $args['slot'];
        $uid = $this->uid($context);
        $bytes = (string) file_get_contents($file->getRealPath());

        // An SVG is recognised by its content, never by its name or the browser's claim.
        if (preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096)) === 1) {
            $this->branding->fromSvg($slot, $bytes, $uid);
        } else {
            $record = ($this->uploads)(new UploadMedia(path: $file->getRealPath(), clientName: $file->getClientOriginalName(), collection: 'branding', uploadedBy: $uid, slot: self::MEDIA_SLOT[$slot]));
            $this->branding->fromMedia($slot, $record->id, isset($args['crop']) ? (array) $args['crop'] : null, $uid);
        }

        return $this->presenter->admin($this->themes->draft($uid));
    }

    /** @param array<string, mixed> $args */
    public function setAsset(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->branding->fromMedia($args['slot'], $args['mediaId'], isset($args['crop']) ? (array) $args['crop'] : null, $this->uid($context));

        return $this->presenter->admin($this->themes->draft());
    }

    /** @param array<string, mixed> $args */
    public function removeAsset(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->branding->remove($args['slot'], $this->uid($context));

        return $this->presenter->admin($this->themes->draft());
    }

    private function uid(GraphQLContext $context): ?string
    {
        $id = $context->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
