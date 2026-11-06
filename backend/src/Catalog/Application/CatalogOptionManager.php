<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InUse;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\CatalogOptionRepository;

/** The short lists of the client (packaging types, units ...), each with its own create / rename / reorder / deactivate / delete. */
final class CatalogOptionManager
{
    /** @var array<string, string> */
    public const CATALOGS = [
        'packaging_type' => 'Tipos de empaque',
        'unit' => 'Unidades (base y presentación)',
        'weight_unit' => 'Unidades de peso',
        'length_unit' => 'Unidades de longitud',
    ];

    public function __construct(private readonly CatalogOptionRepository $options) {}

    /** @return list<array<string, mixed>> */
    public function list(string $catalog, bool $includeInactive = true): array
    {
        $this->assertCatalog($catalog);

        return $this->options->all($catalog, $includeInactive);
    }

    /**
     * @param  array<string, string>  $labels  locale => text
     * @param  array<string, mixed>|null  $meta
     */
    public function create(string $catalog, string $code, array $labels, ?array $meta = null, int $position = 0): int
    {
        $this->assertCatalog($catalog);
        preg_match('/^[a-z0-9][a-z0-9_]{0,39}$/', $code) === 1 || throw new InvalidProduct('The code may only contain lower-case letters, digits and underscores (up to 40).');
        $this->options->findByCode($catalog, $code) === null || throw new InvalidProduct("The code [{$code}] already exists in this list.");

        return $this->options->create(['catalog' => $catalog, 'code' => $code, 'labels' => $this->labels($labels), 'meta' => $this->meta($catalog, $meta), 'position' => $position, 'is_active' => true]);
    }

    /**
     * @param  array<string, string>|null  $labels
     * @param  array<string, mixed>|null  $meta
     */
    public function update(int $id, ?array $labels = null, ?array $meta = null, ?int $position = null, ?bool $isActive = null): void
    {
        $current = $this->options->find($id) ?? throw new NotFound('The option');
        $changes = [];
        $labels === null || $changes['labels'] = $this->labels($labels);
        $meta === null || $changes['meta'] = $this->meta($current['catalog'], $meta);
        $position === null || $changes['position'] = $position;
        $isActive === null || $changes['is_active'] = $isActive;
        $changes === [] || $this->options->update($id, $changes);
    }

    /** Only what nothing uses can be deleted; the rest is deactivated (it stops being offered but old data keeps its meaning). */
    public function delete(int $id): void
    {
        $current = $this->options->find($id) ?? throw new NotFound('The option');
        ! $this->options->isInUse($current['catalog'], $current['code']) || throw new InUse('The option (deactivate it instead)');

        $this->options->delete($id);
    }

    private function assertCatalog(string $catalog): void
    {
        isset(self::CATALOGS[$catalog]) || throw new InvalidProduct("Unknown list [{$catalog}].");
    }

    /** @param array<string, string> $labels @return array<string, string> */
    private function labels(array $labels): array
    {
        $clean = [];
        foreach ($labels as $locale => $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                mb_strlen($text) <= 80 || throw new InvalidProduct('A name is up to 80 characters.');
                $clean[(string) $locale] = $text;
            }
        }
        isset($clean[config('app.locale_default')]) || throw new InvalidProduct('The name in '.config('app.locale_default').' is required.');

        return $clean;
    }

    /** @param array<string, mixed>|null $meta @return ?array<string, mixed> */
    private function meta(string $catalog, ?array $meta): ?array
    {
        $meta ??= [];
        switch ($catalog) {
            case 'unit':
                in_array($meta['sell_unit'] ?? null, ['meter', 'piece'], true) || throw new InvalidProduct('A unit is counted in meters or in pieces (sell_unit).');
                $plural = [];
                foreach ((array) ($meta['plural'] ?? []) as $locale => $text) {
                    trim((string) $text) === '' || $plural[(string) $locale] = mb_substr(trim((string) $text), 0, 80);
                }

                return ['sell_unit' => $meta['sell_unit'], 'base' => (bool) ($meta['base'] ?? false), 'presentation' => (bool) ($meta['presentation'] ?? false), 'roll' => (bool) ($meta['roll'] ?? false), 'plural' => $plural];
            case 'weight_unit':
            case 'length_unit':
                (isset($meta['factor']) && is_numeric($meta['factor']) && (float) $meta['factor'] > 0) || throw new InvalidProduct('Say how many '.($catalog === 'weight_unit' ? 'grams' : 'millimetres').' one unit is (factor above zero).');

                return ['factor' => (string) $meta['factor']];
            default:
                return null;
        }
    }
}
