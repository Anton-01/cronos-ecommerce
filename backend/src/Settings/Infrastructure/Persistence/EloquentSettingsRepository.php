<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure\Persistence;

use Cronos\Settings\Domain\Ports\SettingsRepository;
use Cronos\Settings\Domain\ValueObjects\StoredSetting;
use Cronos\Settings\Infrastructure\Persistence\Eloquent\SettingModel;
use Cronos\Settings\Infrastructure\Persistence\Eloquent\SettingTranslationModel;

final class EloquentSettingsRepository implements SettingsRepository
{
    public function all(): array
    {
        $out = [];

        foreach (SettingModel::query()->with('translations')->get() as $setting) {
            $out["{$setting->group}.{$setting->key}"] = new StoredSetting(
                $setting->group,
                $setting->key,
                $setting->value,
                $setting->translations->pluck('value', 'locale')->all(),
            );
        }

        return $out;
    }

    public function save(string $group, string $key, mixed $value, array $translations, ?string $updatedBy): void
    {
        $setting = SettingModel::query()->firstOrNew(['group' => $group, 'key' => $key]);
        $setting->value = $value;
        $setting->updated_by = $updatedBy;
        $setting->save();

        SettingTranslationModel::query()->where('setting_id', $setting->id)->delete();

        foreach (array_filter($translations, fn ($text) => $text !== null) as $locale => $text) {
            SettingTranslationModel::query()->create(['setting_id' => $setting->id, 'locale' => $locale, 'value' => $text]);
        }
    }

    public function reset(string $group, string $key): void
    {
        // Translations go with it (ON DELETE CASCADE).
        SettingModel::query()->where(['group' => $group, 'key' => $key])->get()->each->delete();
    }
}
