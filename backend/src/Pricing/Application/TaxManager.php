<?php

declare(strict_types=1);

namespace Cronos\Pricing\Application;

use Cronos\Pricing\Domain\Exceptions\Conflict;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Pricing\Domain\Exceptions\NotFound;
use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Domain\Ports\TaxStore;
use Cronos\Pricing\Domain\ValueObjects\TaxRate;

final class TaxManager
{
    public function __construct(private readonly TaxStore $taxes, private readonly MarketStore $markets) {}

    public function createClass(string $name, bool $isDefault = false): int
    {
        $name = $this->className($name);
        ! $this->taxes->classNameTaken($name) || throw new Conflict("The tax class {$name} already exists.", 'TAX_CLASS_EXISTS');

        return $this->taxes->createClass(['name' => $name, 'is_default' => $isDefault || $this->taxes->classes() === []]);
    }

    public function updateClass(int $id, ?string $name, ?bool $isDefault): void
    {
        $current = $this->taxes->findClass($id) ?? throw new NotFound('The tax class');
        $data = [];
        if ($name !== null) {
            $name = $this->className($name);
            ! $this->taxes->classNameTaken($name, $id) || throw new Conflict("The tax class {$name} already exists.", 'TAX_CLASS_EXISTS');
            $data['name'] = $name;
        }
        if ($isDefault !== null) {
            ($isDefault || ! $current['is_default']) || throw new Conflict('Pick another default class instead of unsetting this one.', 'DEFAULT_TAX_CLASS');
            $data['is_default'] = $isDefault;
        }

        $this->taxes->updateClass($id, $data);
    }

    public function deleteClass(int $id): void
    {
        $current = $this->taxes->findClass($id) ?? throw new NotFound('The tax class');
        ! $current['is_default'] || throw new Conflict('The default tax class cannot be deleted.', 'DEFAULT_TAX_CLASS');
        ! $this->taxes->classInUse($id) || throw new Conflict('Products still use this tax class.', 'TAX_CLASS_IN_USE');

        $this->taxes->deleteClass($id);
    }

    /** @param array<string, mixed> $data tax_class_id, market_id, region, name, rate, priority, is_compound, starts_at, ends_at */
    public function createRate(array $data): int
    {
        return $this->taxes->createRate($this->validRate($data));
    }

    /** @param array<string, mixed> $data */
    public function updateRate(int $id, array $data): void
    {
        $current = $this->taxes->findRate($id) ?? throw new NotFound('The tax rate');

        $this->taxes->updateRate($id, $this->validRate($data + $current));
    }

    public function deleteRate(int $id): void
    {
        $this->taxes->findRate($id) ?? throw new NotFound('The tax rate');

        $this->taxes->deleteRate($id);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validRate(array $data): array
    {
        $this->taxes->findClass((int) $data['tax_class_id']) ?? throw new NotFound('The tax class');
        $this->markets->find((int) $data['market_id']) ?? throw new NotFound('The market');
        $vo = new TaxRate((string) $data['name'], (string) $data['rate'], (int) ($data['priority'] ?? 0), (bool) ($data['is_compound'] ?? false));

        $region = isset($data['region']) && trim((string) $data['region']) !== '' ? strtoupper(trim((string) $data['region'])) : null;
        $region === null || preg_match('/^[A-Z0-9-]{1,10}$/', $region) || throw new InvalidPricing('A region is a state/province code such as CA or CDMX.');
        $starts = $data['starts_at'] ?? null;
        $ends = $data['ends_at'] ?? null;
        ($starts === null || $ends === null || strtotime((string) $starts) < strtotime((string) $ends)) || throw new InvalidPricing('The rate must end after it starts.', 'INVALID_WINDOW');

        return [
            'tax_class_id' => (int) $data['tax_class_id'], 'market_id' => (int) $data['market_id'], 'region' => $region, 'name' => trim($vo->name),
            'rate' => (string) $vo->rate, 'priority' => $vo->priority, 'is_compound' => $vo->isCompound, 'starts_at' => $starts, 'ends_at' => $ends,
        ];
    }

    private function className(string $name): string
    {
        $name = trim($name);
        ($name !== '' && mb_strlen($name) <= 80) || throw new InvalidPricing('A tax class needs a name (up to 80 characters).');

        return $name;
    }
}
