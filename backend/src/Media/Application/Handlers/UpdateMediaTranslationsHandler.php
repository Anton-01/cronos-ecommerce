<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\UpdateMediaTranslations;
use Cronos\Media\Domain\Exceptions\MediaNotFound;
use Cronos\Media\Domain\Exceptions\UnknownLocale;
use Cronos\Media\Domain\Ports\MediaRepository;

final class UpdateMediaTranslationsHandler
{
    public function __construct(private readonly MediaRepository $media) {}

    public function __invoke(UpdateMediaTranslations $command): void
    {
        $this->media->find($command->id) ?? throw new MediaNotFound;

        $supported = (array) config('app.supported_locales');
        foreach (array_keys($command->translations) as $locale) {
            in_array($locale, $supported, true) || throw new UnknownLocale((string) $locale);
        }

        $this->media->syncTranslations($command->id, $command->translations);
    }
}
