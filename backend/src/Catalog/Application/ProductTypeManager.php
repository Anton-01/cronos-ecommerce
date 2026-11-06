<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InUse;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\ProductTypeRepository;
use Cronos\Catalog\Domain\Services\SpecFields;

/** Kinds of product (ribbon, decorative piece ...): what they require, what defines their variants, how they are sold, how their SKUs start. */
final class ProductTypeManager
{
    public function __construct(private readonly ProductTypeRepository $types, private readonly AttributeRepository $attributes) {}

    /** @return list<array<string, mixed>> */
    public function list(bool $includeInactive = true): array
    {
        return $this->types->all($includeInactive);
    }

    /** @param array<string, mixed> $input code, names{locale:text}, sku_prefix, sales{...}, required_fields[], variant_attribute_ids[], product_attribute_ids[], position */
    public function create(array $input): string
    {
        $code = (string) ($input['code'] ?? '');
        preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $code) === 1 || throw new InvalidProduct('The code is 2-32 lower-case letters, digits, dash or underscore.');
        ! $this->types->codeTaken($code) || throw new InvalidProduct("The code [{$code}] already exists.");

        return $this->types->create($this->clean($input) + ['code' => $code, 'is_active' => true]);
    }

    /** @param array<string, mixed> $input only the keys present change */
    public function update(string $id, array $input): void
    {
        $current = $this->types->find($id) ?? throw new NotFound('The product type');
        $changes = $this->clean($input + $current, partial: array_keys($input));
        if (array_key_exists('is_active', $input)) {
            $changes['is_active'] = (bool) $input['is_active'];
        }
        $changes === [] || $this->types->update($id, $changes);
    }

    public function delete(string $id): void
    {
        $this->types->find($id) ?? throw new NotFound('The product type');
        ! $this->types->isInUse($id) || throw new InUse('The product type (deactivate it instead)');
        $this->types->delete($id);
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  ?list<string>  $partial  keys to keep (null = all)
     * @return array<string, mixed>
     */
    private function clean(array $in, ?array $partial = null): array
    {
        $out = [];
        $want = fn (string $k) => $partial === null || in_array($k, $partial, true);

        if ($want('names')) {
            $names = array_filter(array_map(fn ($t) => trim((string) $t), (array) ($in['names'] ?? [])), fn ($t) => $t !== '');
            isset($names[config('app.locale_default')]) || throw new InvalidProduct('The name in '.config('app.locale_default').' is required.');
            $out['names'] = $names;
        }
        if ($want('sku_prefix')) {
            $prefix = strtoupper(trim((string) ($in['sku_prefix'] ?? '')));
            preg_match('/^[A-Z0-9]{1,8}$/', $prefix) === 1 || throw new InvalidProduct('The SKU prefix is 1-8 letters or digits.');
            $out['sku_prefix'] = $prefix;
        }
        if ($want('sales')) {
            $sales = (array) ($in['sales'] ?? []);
            $unit = Input::salesUnit($sales + ['unit' => 'piece']); // validates the combination (a piece cannot be cut, a roll needs its length ...)
            $out['sales'] = ['unit' => $unit->unit, 'step' => (string) $unit->step, 'min' => (string) $unit->min, 'sell_by_cut' => $unit->sellByCut, 'sell_by_roll' => $unit->sellByRoll];
        }
        if ($want('required_fields')) {
            $required = array_values(array_unique((array) ($in['required_fields'] ?? [])));
            foreach ($required as $f) {
                in_array($f, SpecFields::requirable(), true) || throw new InvalidProduct("[{$f}] is not a field of the data sheet.");
            }
            $out['required_fields'] = $required;
        }
        foreach (['variant_attribute_ids', 'product_attribute_ids'] as $key) {
            if ($want($key)) {
                $ids = array_values(array_unique((array) ($in[$key] ?? [])));
                foreach ($ids as $id) {
                    $this->attributes->find((string) $id) ?? throw new NotFound('One of the attributes');
                }
                $out[$key] = $ids;
            }
        }
        if ($want('variant_attribute_ids') || $want('product_attribute_ids')) {
            array_intersect($in['variant_attribute_ids'] ?? [], $in['product_attribute_ids'] ?? []) === [] || throw new InvalidProduct('An attribute either defines the variants or describes the product, not both.');
        }
        if ($want('position')) {
            $out['position'] = (int) ($in['position'] ?? 0);
        }

        return $out;
    }
}
