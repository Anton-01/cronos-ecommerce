<?php

declare(strict_types=1);

namespace Cronos\Settings\Application\Listeners;

use Cronos\Media\Domain\Events\MediaChanged;
use Cronos\Settings\Application\SettingsSnapshot;

/** A logo that is renamed, re-processed or deleted must not leave a stale URL in the cache. */
final class RefreshSettingsWhenMediaChanges
{
    public function __construct(private readonly SettingsSnapshot $snapshot) {}

    public function handle(MediaChanged $event): void
    {
        if ($this->snapshot->referencesMedia($event->mediaId)) {
            $this->snapshot->refresh();
        }
    }
}
