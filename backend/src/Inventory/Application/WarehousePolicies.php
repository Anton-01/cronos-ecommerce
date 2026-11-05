<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Ports\AlertStore;

/** The rules the administrator can change without a deployment. Every change is a new row: who changed what, when, from what. */
final class WarehousePolicies
{
    /** @var array<string, array{default: int, max: int, label: string}> */
    public const KEYS = [
        'approval_units' => ['default' => 5, 'max' => 1000000, 'label' => 'Un ajuste de más de N unidades necesita aprobación (0 = no se usa)'],
        'approval_percent' => ['default' => 0, 'max' => 1000, 'label' => 'Un ajuste de más de N % de lo que había necesita aprobación (0 = no se usa)'],
        'approval_amount' => ['default' => 0, 'max' => 100000000, 'label' => 'Un ajuste que valga más de N (moneda principal) necesita aprobación (0 = no se usa)'],
        'recovery_margin_percent' => ['default' => 10, 'max' => 100, 'label' => 'Margen para dar por recuperada una existencia (evita avisos repetidos)'],
    ];

    public function __construct(private readonly AlertStore $store) {}

    /** @return array{approval_units: int, approval_percent: int, approval_amount: int, recovery_margin_percent: int} */
    public function current(): array
    {
        $stored = $this->store->policyValues();
        $out = [];
        foreach (self::KEYS as $key => $meta) {
            $out[$key] = isset($stored[$key]) ? (int) $stored[$key] : (int) config("inventory.{$key}", $meta['default']);
        }

        return $out;
    }

    /**
     * @param  array<string, int|string>  $changes
     * @return list<string> the keys that really changed
     */
    public function update(array $changes, ?string $userId): array
    {
        foreach ($changes as $key => $value) {
            isset(self::KEYS[$key]) || throw new InvalidMovement("Unknown policy [{$key}].", 'UNKNOWN_POLICY');
            (is_numeric($value) && (int) $value == $value && (int) $value >= 0 && (int) $value <= self::KEYS[$key]['max']) || throw new InvalidMovement("The value of {$key} must be a whole number between 0 and ".self::KEYS[$key]['max'].'.', 'INVALID_POLICY_VALUE');
        }
        $next = array_map('intval', $changes) + $this->current();
        ($next['approval_units'] > 0 || $next['approval_percent'] > 0 || $next['approval_amount'] > 0)
            || throw new InvalidMovement('At least one approval limit must stay active, otherwise anyone could change any quantity unchecked.', 'POLICY_WOULD_DISABLE_APPROVAL');

        $current = $this->current();

        return $this->store->atomically(function () use ($changes, $current, $userId): array {
            $done = [];
            foreach ($changes as $key => $value) {
                if ((int) $value !== $current[$key]) {
                    $this->store->savePolicy($key, (string) (int) $value, $userId);
                    $done[] = $key;
                }
            }

            return $done;
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 100): array
    {
        return $this->store->policyHistory($limit);
    }
}
