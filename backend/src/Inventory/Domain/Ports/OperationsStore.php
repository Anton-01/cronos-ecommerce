<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

/** Storage for what the warehouse team does around the ledgers: pick progress, adjustment requests and count sessions. */
interface OperationsStore
{
    public function atomically(callable $work): mixed;

    /** Waits for (and holds until the transaction ends) a lock named by the key. Always taken BEFORE any stock row lock. */
    public function advisoryLock(string $key): void;

    /** Total already taken from shelves for an order line (positive number). */
    public function pickedFor(string $orderId, string $productId, int $warehouseId): string;

    /** @return list<array{order_id: string, product_id: string, warehouse_id: int, sold: string, returned: string, picked: string}> lines still to put in a box */
    public function pickLines(?int $warehouseId, int $limit = 200): array;

    /** @param array<string, mixed> $row @return string id */
    public function createRequest(array $row): string;

    /** @return array<string, mixed>|null */
    public function findRequest(string $id, bool $lock = false): ?array;

    /** @return array<string, mixed>|null */
    public function requestByKey(string $key): ?array;

    public function decideRequest(string $id, string $status, ?string $userId, ?string $note, ?string $movementUlid): void;

    /** @return list<array<string, mixed>> */
    public function requests(?string $status, ?int $warehouseId, int $limit = 100): array;

    /** @param array<string, mixed> $row @return string id */
    public function createSession(array $row): string;

    /** Freezes what every shelf of the scope holds right now. Returns how many rows. */
    public function snapshotSession(string $sessionId, int $warehouseId, ?string $scopeLocationId): int;

    /** @return array<string, mixed>|null */
    public function findSession(string $id): ?array;

    public function closeSession(string $id, ?string $userId): void;

    public function snapshotQuantity(string $sessionId, string $productId, string $locationId): string;

    /** @return list<array<string, mixed>> oldest first */
    public function entriesFor(string $sessionId, string $productId, string $locationId): array;

    /** @param array<string, mixed> $row @return string id */
    public function createEntry(array $row): string;

    /** @return array<string, mixed>|null */
    public function findEntry(string $id, bool $lock = false): ?array;

    /** @return array<string, mixed>|null */
    public function entryByKey(string $key): ?array;

    public function reviewEntry(string $id, string $status, ?string $userId, ?string $note, ?string $movementUlid): void;

    /** What count adjustments of this session already moved on this shelf (signed). */
    public function appliedCountDelta(string $sessionId, string $productId, string $locationId): string;

    public function entriesInReview(?string $sessionId): int;

    /** @return list<array<string, mixed>> */
    public function reviewQueue(?string $sessionId, int $limit = 100): array;
}
