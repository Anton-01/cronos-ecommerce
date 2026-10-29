<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InUse;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\AttributeRepository;

final class AttributeManager
{
    public function __construct(private readonly AttributeRepository $attributes) {}

    /** @param array<string, string> $names locale => name */
    public function create(string $code, string $type, bool $isFilterable, int $position, array $names): string
    {
        $this->assertCode($code);
        in_array($type, ['select', 'color', 'text', 'number'], true) || throw new InvalidProduct("Unknown attribute type [{$type}].");
        ! $this->attributes->codeTaken($code) || throw new InvalidProduct("The attribute code [{$code}] already exists.");

        return $this->attributes->create($code, $type, $isFilterable, $position, $this->names($names));
    }

    /** @param array{type?: string, is_filterable?: bool, position?: int} $attributes @param array<string, string>|null $names */
    public function update(string $id, array $attributes, ?array $names): void
    {
        $current = $this->attributes->find($id) ?? throw new NotFound('The attribute');

        // Changing the type of an attribute that products already use would invalidate their values.
        ! (isset($attributes['type']) && $attributes['type'] !== $current['type'] && $this->attributes->isInUse($id)) || throw new InUse('The attribute type');

        $this->attributes->update($id, $attributes, $names === null ? null : $this->names($names));
    }

    public function delete(string $id): void
    {
        $this->attributes->find($id) ?? throw new NotFound('The attribute');
        ! $this->attributes->isInUse($id) || throw new InUse('The attribute');

        $this->attributes->delete($id);
    }

    /**
     * @param  array<string, string>  $labels  locale => label
     * @param  array<string, mixed>|null  $meta  e.g. ['hex' => '#C8285A'] for colour attributes
     */
    public function addValue(string $attributeId, string $code, ?array $meta, int $position, array $labels): string
    {
        $attribute = $this->attributes->find($attributeId) ?? throw new NotFound('The attribute');
        // Value codes may start with a digit ("10mm"); attribute codes may not.
        (bool) preg_match('/^[a-z0-9][a-z0-9_]{0,62}$/', $code) || throw new InvalidProduct('The code may only contain lower-case letters, digits and underscores.');
        ! $this->attributes->valueCodeTaken($attributeId, $code) || throw new InvalidProduct("The value code [{$code}] already exists for this attribute.");
        $this->assertMeta($attribute['type'], $meta);

        return $this->attributes->addValue($attributeId, $code, $meta, $position, $this->names($labels));
    }

    /** @param array{meta?: ?array<string, mixed>, position?: int} $attributes @param array<string, string>|null $labels */
    public function updateValue(string $valueId, array $attributes, ?array $labels): void
    {
        $value = $this->attributes->findValue($valueId) ?? throw new NotFound('The attribute value');
        if (array_key_exists('meta', $attributes)) {
            $this->assertMeta($this->attributes->find($value['attribute_id'])['type'], $attributes['meta']);
        }

        $this->attributes->updateValue($valueId, $attributes, $labels === null ? null : $this->names($labels));
    }

    public function deleteValue(string $valueId): void
    {
        $this->attributes->findValue($valueId) ?? throw new NotFound('The attribute value');
        ! $this->attributes->isValueInUse($valueId) || throw new InUse('The attribute value');

        $this->attributes->deleteValue($valueId);
    }

    private function assertCode(string $code): void
    {
        (bool) preg_match('/^[a-z][a-z0-9_]{0,62}$/', $code) || throw new InvalidProduct('The code must start with a letter and use lower-case letters, digits and underscores.');
    }

    /** @param array<string, mixed>|null $meta */
    private function assertMeta(string $type, ?array $meta): void
    {
        if ($type === 'color') {
            (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($meta['hex'] ?? '')) || throw new InvalidProduct('Colour values need a hex code such as #C8285A.');
        }
    }

    /** @param array<string, string> $names @return array<string, string> */
    private function names(array $names): array
    {
        $out = [];
        foreach ($names as $locale => $name) {
            in_array($locale, Localized::supported(), true) || throw new InvalidProduct("Locale [{$locale}] is not supported.");
            $name = trim($name);
            $name !== '' || throw new InvalidProduct("The name in {$locale} cannot be empty.");
            $out[$locale] = $name;
        }
        isset($out[Localized::default()]) || throw new InvalidProduct('The '.Localized::default().' name is required.');

        return $out;
    }
}
