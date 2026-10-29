<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

interface AttributeRepository
{
    /** @return array{id: string, code: string, type: string, is_filterable: bool, position: int, translations: array<string, string>}|null */
    public function find(string $id): ?array;

    /** @param array<string, string> $names locale => name @return string id */
    public function create(string $code, string $type, bool $isFilterable, int $position, array $names): string;

    /** @param array{type?: string, is_filterable?: bool, position?: int} $attributes @param array<string, string>|null $names */
    public function update(string $id, array $attributes, ?array $names): void;

    public function delete(string $id): void;

    public function codeTaken(string $code, ?string $exceptId = null): bool;

    /** True when any product still uses the attribute. */
    public function isInUse(string $id): bool;

    /** @param array<string, string> $labels locale => label @param array<string, mixed>|null $meta @return string id */
    public function addValue(string $attributeId, string $code, ?array $meta, int $position, array $labels): string;

    /** @param array{meta?: ?array<string, mixed>, position?: int} $attributes @param array<string, string>|null $labels */
    public function updateValue(string $valueId, array $attributes, ?array $labels): void;

    public function deleteValue(string $valueId): void;

    public function valueCodeTaken(string $attributeId, string $code, ?string $exceptId = null): bool;

    public function isValueInUse(string $valueId): bool;

    /** @return array{id: string, attribute_id: string}|null */
    public function findValue(string $valueId): ?array;

    /**
     * @param  list<string>  $valueIds
     * @return array<string, string> value id => code (e.g. "rojo")
     */
    public function codesOfValues(array $valueIds): array;

    /**
     * Maps each value id to its attribute id (unknown ids are omitted).
     *
     * @param  list<string>  $valueIds
     * @return array<string, string>
     */
    public function attributeIdsOfValues(array $valueIds): array;
}
