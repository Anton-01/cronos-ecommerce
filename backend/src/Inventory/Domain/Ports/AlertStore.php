<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

/** Storage of thresholds, states, alerts, who subscribes to what, and the admin-editable policies. */
interface AlertStore
{
    public function atomically(callable $work): mixed;

    // ---- thresholds
    /** @return array{minimum: string, critical: ?string}|null  per-place threshold; for the warehouse scope falls back to the product's own low-stock threshold */
    public function threshold(string $productId, int $warehouseId, ?string $locationId): ?array;

    public function saveThreshold(string $productId, int $warehouseId, ?string $locationId, ?string $minimum, ?string $critical, ?string $userId): void;

    /** @return list<array<string, mixed>> current thresholds (removed ones excluded) */
    public function thresholds(?int $warehouseId, int $limit = 500): array;

    // ---- states
    /** Locks (creating at 'ok' if missing) the state row of one product+place. @return array{state: string} */
    public function lockState(string $productId, int $warehouseId, ?string $locationId): array;

    public function saveState(string $productId, int $warehouseId, ?string $locationId, string $state, string $quantity): void;

    /** Quantity the place holds: the warehouse's available units, or what one shelf holds. */
    public function quantityAt(string $productId, int $warehouseId, ?string $locationId): string;

    /** @return list<int> warehouses where the product has stock rows */
    public function warehousesOf(string $productId): array;

    /** @return array{sku: ?string, name: ?string} */
    public function describeProduct(string $productId): array;

    /** @return array{code: string, name: string}|null */
    public function describeLocation(string $locationId): ?array;

    public function warehouseName(int $warehouseId): ?string;

    // ---- alerts
    /**
     * @param  array<string, mixed>  $alert  id, process, severity, title, detail, data, product_id, warehouse_id, location_id, dedupe_key
     * @param  list<array{user_id: string, by_email: bool}>  $recipients
     * @return bool false when an alert with this dedupe key already existed
     */
    public function createAlert(array $alert, array $recipients): bool;

    /** @return list<array<string, mixed>> */
    public function inbox(string $userId, bool $unreadOnly, int $limit = 50): array;

    public function unreadCount(string $userId): int;

    /** @param list<string>|null $alertIds null = everything unread */
    public function markRead(string $userId, ?array $alertIds): int;

    // ---- subscriptions
    /** @return list<array{user_id: string, inbox: bool, email: bool}> */
    public function subscribers(string $process): array;

    public function subscribe(string $process, string $userId, bool $inbox, bool $email, ?string $by): void;

    public function unsubscribe(string $process, string $userId, ?string $by): void;

    /** @return list<array<string, mixed>> */
    public function subscriptionLog(?string $process, int $limit = 100): array;

    // ---- policies
    /** @return array<string, string> latest value of each key that was ever set */
    public function policyValues(): array;

    public function savePolicy(string $key, string $value, ?string $by): void;

    /** @return list<array<string, mixed>> */
    public function policyHistory(int $limit = 100): array;

    // ---- digest
    /** @return array{low: int, critical: int, out: int, pending_requests: int, counts_in_review: int} */
    public function digestFigures(): array;
}
