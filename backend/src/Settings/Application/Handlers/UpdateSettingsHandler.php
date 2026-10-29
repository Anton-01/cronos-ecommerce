<?php

declare(strict_types=1);

namespace Cronos\Settings\Application\Handlers;

use Cronos\Settings\Application\Commands\UpdateSettings;
use Cronos\Settings\Application\SettingsSnapshot;
use Cronos\Settings\Application\SettingValueValidator;
use Cronos\Settings\Domain\Ports\SettingsRepository;
use Cronos\Settings\Domain\SettingDefinitions;
use Illuminate\Support\Facades\DB;

final class UpdateSettingsHandler
{
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly SettingValueValidator $validator,
        private readonly SettingsSnapshot $snapshot,
    ) {}

    public function __invoke(UpdateSettings $command): void
    {
        // Validate everything first: either all changes apply or none does.
        $prepared = [];
        foreach ($command->changes as $change) {
            $definition = SettingDefinitions::get($change['group'], $change['key']);
            $prepared[] = [$definition, ...$this->validator->normalise($definition, $change)];
        }

        DB::transaction(function () use ($prepared, $command): void {
            foreach ($prepared as [$definition, $value, $translations]) {
                $empty = $value === null && array_filter($translations, fn ($t) => $t !== null) === [];

                $empty
                    ? $this->repository->reset($definition->group, $definition->key)
                    : $this->repository->save($definition->group, $definition->key, $value, $translations, $command->updatedBy);
            }
        });

        // Write-through so readers never see stale data, even if the observer was bypassed.
        $this->snapshot->refresh();
    }
}
