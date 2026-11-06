<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\CatalogOptionRepository;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Ports\ProductSpecStore;
use Cronos\Catalog\Domain\Ports\ProductTypeRepository;
use Cronos\Catalog\Domain\Services\SpecFields;
use Cronos\Catalog\Domain\Services\SpecStates;

/**
 * The data sheet of a product: values, and for what has no value whether it is pending or does not apply.
 * Nothing is invented: an empty field is "pending" until someone says otherwise.
 */
final class ProductSpecs
{
    public function __construct(
        private readonly ProductSpecStore $store,
        private readonly ProductRepository $products,
        private readonly ProductTypeRepository $types,
        private readonly CatalogOptionRepository $options,
        private readonly AttributeRepository $attributes,
    ) {}

    /**
     * @return array{product_id: string, type_id: ?string, specs: array<string, mixed>, states: array<string, string>, required: list<string>, missing: list<string>}
     */
    public function get(string $productId): array
    {
        $this->products->find($productId) ?? throw new NotFound('The product');
        $row = $this->store->find($productId);
        $type = ($row['product_type_id'] ?? null) === null ? null : $this->types->find($row['product_type_id']);
        $specs = $row['specs'] ?? [];
        $stored = $row['states'] ?? [];
        $required = $type['required_fields'] ?? [];

        $states = [];
        foreach (SpecFields::keys() as $field) {
            $states[$field] = SpecStates::of($field, $specs, $stored);
        }

        return ['product_id' => $productId, 'type_id' => $type['id'] ?? null, 'specs' => $specs, 'states' => $states, 'required' => $required, 'missing' => SpecStates::missing($required, $specs, $stored)];
    }

    /**
     * Only the keys present change. A value of null/"" clears a field; `states` says what an empty field is (pending or not_applicable).
     *
     * @param  array<string, mixed>  $specs
     * @param  array<string, ?string>  $states
     * @return array{product_id: string, type_id: ?string, specs: array<string, mixed>, states: array<string, string>, required: list<string>, missing: list<string>}
     */
    public function update(string $productId, array $specs, array $states, ?string $typeId, bool $typeGiven, ?string $userId): array
    {
        $product = $this->products->find($productId) ?? throw new NotFound('The product');
        in_array($product['type'], ['simple', 'variable', 'variation'], true) || throw new InvalidProduct('Grouped products have no data sheet.');
        $current = $this->store->find($productId);

        $nextSpecs = $current['specs'] ?? [];
        $nextStates = $current['states'] ?? [];
        foreach ($specs as $field => $value) {
            $this->assertField((string) $field, $product['type']);
            $clean = $this->value((string) $field, $value);
            if ($clean === null) {
                unset($nextSpecs[$field]);
            } else {
                $nextSpecs[$field] = $clean;
            }
        }
        foreach ($states as $field => $state) {
            $this->assertField((string) $field, $product['type']);
            ($state === null || in_array($state, [...SpecFields::STATES, SpecStates::VALUE], true)) || throw new InvalidProduct('A field is pending or not applicable.');
            if ($state === SpecStates::NOT_APPLICABLE) {
                $nextStates[$field] = SpecStates::NOT_APPLICABLE;
            } else {
                unset($nextStates[$field]); // pending is what an empty field is; "value" is decided by the value itself
            }
        }
        // A value always wins over a stored state.
        foreach (array_keys($nextStates) as $field) {
            SpecFields::isEmpty($nextSpecs[$field] ?? null) || $nextStates[$field] = null;
        }
        $nextStates = array_filter($nextStates, fn ($s) => $s !== null);

        $typeId = $typeGiven ? $typeId : ($current['product_type_id'] ?? null);
        $typeId === null || $this->types->find($typeId) !== null || throw new NotFound('The product type');

        $this->store->save($productId, $typeId, $nextSpecs, $nextStates, $userId);

        return $this->get($productId);
    }

    /** Required fields of the product's type that are still pending: what blocks publishing. @return list<string> labels */
    public function missingLabels(string $productId): array
    {
        $sheet = $this->get($productId);

        return array_map(fn (string $f) => SpecFields::ALL[$f]['label'], $sheet['missing']);
    }

    private function assertField(string $field, string $productType): void
    {
        isset(SpecFields::ALL[$field]) || throw new InvalidProduct("[{$field}] is not a field of the data sheet.");
        ($productType === 'variation' ? SpecFields::ALL[$field]['scope'] === 'variant' : true) || throw new InvalidProduct('['.SpecFields::ALL[$field]['label'].'] belongs to the product, not to one variant.');
        ($productType !== 'variable' || SpecFields::ALL[$field]['scope'] === 'product') || throw new InvalidProduct('['.SpecFields::ALL[$field]['label'].'] is filled in each variant.');
    }

    private function value(string $field, mixed $value): mixed
    {
        $def = SpecFields::ALL[$field];
        if (SpecFields::isEmpty($value)) {
            return null;
        }

        switch ($def['kind']) {
            case SpecFields::TEXT:
                $text = trim((string) $value);
                mb_strlen($text) <= ($def['max'] ?? 255) || throw new InvalidProduct("{$def['label']}: up to ".($def['max'] ?? 255).' characters.');

                return $text === '' ? null : $text;

            case SpecFields::OPTION:
                $code = (string) $value;
                $option = $this->options->findByCode($def['catalog'], $code) ?? throw new InvalidProduct("{$def['label']}: [{$code}] is not in the list.");
                if ($field === 'base_unit') {
                    ($option['meta']['base'] ?? false) || throw new InvalidProduct('That unit cannot be a base unit.');
                }
                if ($field === 'presentation_unit') {
                    ($option['meta']['presentation'] ?? false) || throw new InvalidProduct('That unit cannot be a presentation.');
                }

                return $code;

            case SpecFields::MEASURE:
                is_array($value) || throw new InvalidProduct("{$def['label']}: send value and unit.");
                $number = $this->decimal($value['value'] ?? null, $def['label']);
                $unit = (string) ($value['unit'] ?? '');
                $this->options->findByCode($def['unit_catalog'], $unit) !== null || throw new InvalidProduct("{$def['label']}: [{$unit}] is not a valid unit.");

                return ['value' => $number, 'unit' => $unit];

            case SpecFields::NUMBER:
                $n = $this->decimal($value, $def['label']);
                (float) $n > 0 || throw new InvalidProduct("{$def['label']} must be above zero.");

                return $n;

            case SpecFields::COLORS:
                $ids = array_values(array_unique(array_map('strval', (array) $value)));
                $owners = $this->attributes->attributeIdsOfValues($ids);
                count($owners) === count($ids) || throw new InvalidProduct("{$def['label']}: a colour does not exist.");
                foreach (array_unique($owners) as $attributeId) {
                    ($this->attributes->find($attributeId)['code'] ?? null) === 'color' || throw new InvalidProduct("{$def['label']} are chosen from the colours.");
                }

                return $ids;

            case SpecFields::TAGS:
                $tags = [];
                foreach ((array) $value as $t) {
                    $t = trim((string) $t);
                    $t === '' || $tags[mb_strtolower($t)] ??= mb_substr($t, 0, 60);
                }
                count($tags) <= ($def['max'] ?? 20) || throw new InvalidProduct("{$def['label']}: at most ".($def['max'] ?? 20).' items.');

                return array_values($tags);
        }

        return null;
    }

    private function decimal(mixed $v, string $label): string
    {
        (is_numeric($v) && (float) $v >= 0 && (float) $v <= 999999.999) || throw new InvalidProduct("{$label} must be a number between 0 and 999999.999.");
        $s = (string) $v;
        (! str_contains($s, '.') || strlen(explode('.', $s)[1]) <= 3) || throw new InvalidProduct("{$label} has at most three decimals.");

        return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
