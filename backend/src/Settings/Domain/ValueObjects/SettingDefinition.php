<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain\ValueObjects;

/** One configurable key. The catalogue is code, so visibility can never be widened by data. */
final readonly class SettingDefinition
{
    public const TYPES = ['string', 'text', 'email', 'url', 'phone', 'bool', 'int', 'media'];

    public function __construct(
        public string $group,
        public string $key,
        public string $type,
        public string $label,
        public bool $translatable = false,
        public bool $public = false,
        public mixed $default = null,
    ) {
        in_array($type, self::TYPES, true) || throw new \InvalidArgumentException("Unknown setting type [{$type}].");
        ! ($translatable && ! in_array($type, ['string', 'text'], true)) || throw new \InvalidArgumentException('Only string/text settings can be translated.');
    }

    public function id(): string
    {
        return "{$this->group}.{$this->key}";
    }
}
