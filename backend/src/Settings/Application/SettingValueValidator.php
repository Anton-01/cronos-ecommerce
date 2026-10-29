<?php

declare(strict_types=1);

namespace Cronos\Settings\Application;

use Cronos\Settings\Domain\Exceptions\InvalidSettingValue;
use Cronos\Settings\Domain\Exceptions\UnknownLocale;
use Cronos\Settings\Domain\Ports\MediaLookup;
use Cronos\Settings\Domain\ValueObjects\SettingDefinition;
use Illuminate\Support\Facades\Validator;

final class SettingValueValidator
{
    public function __construct(private readonly MediaLookup $media) {}

    /**
     * @param  array{value?: mixed, translations?: array<string, ?string>}  $change
     * @return array{0: mixed, 1: array<string, ?string>} [value, translations]
     */
    public function normalise(SettingDefinition $definition, array $change): array
    {
        if ($definition->translatable) {
            $translations = [];
            $supported = (array) config('app.supported_locales');

            foreach ($change['translations'] ?? [] as $locale => $text) {
                in_array($locale, $supported, true) || throw new UnknownLocale((string) $locale);
                $translations[$locale] = $this->check($definition, $this->blankToNull($text));
            }

            return [null, $translations];
        }

        return [$this->check($definition, $this->blankToNull($change['value'] ?? null)), []];
    }

    private function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
    }

    private function check(SettingDefinition $d, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $rules = match ($d->type) {
            'string' => ['string', 'max:255'],
            'text' => ['string', 'max:20000'],
            'email' => ['string', 'email:rfc', 'max:255'],
            // http(s) only: javascript:, data: and friends would become stored XSS in the storefront footer.
            'url' => ['string', 'max:2048', 'url:http,https'],
            'phone' => ['string', 'regex:/^\+?[0-9 ()\-]{6,25}$/'],
            'bool' => ['boolean'],
            'int' => ['integer'],
            'media' => ['string', 'size:26'],
        };

        $validator = Validator::make(['value' => $value], ['value' => $rules]);
        $validator->passes() || throw new InvalidSettingValue($d->id(), (string) $validator->errors()->first('value'));

        if ($d->type === 'media' && ! $this->media->isUsableImage((string) $value)) {
            throw new InvalidSettingValue($d->id(), 'the media item does not exist or is not an image.');
        }

        return $d->type === 'bool' ? (bool) $value : ($d->type === 'int' ? (int) $value : $value);
    }
}
