<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

interface ReservationStore
{
    /** @return array<string, mixed>|null the active reservation of a holder for one product/warehouse */
    public function active(string $holderType, string $holderId, string $productId, int $warehouseId): ?array;

    /** @return list<array<string, mixed>> */
    public function activeOf(string $holderType, string $holderId): array;

    /** @return list<array<string, mixed>> active reservations, optionally of one product */
    public function activeList(?string $productId = null, int $limit = 200): array;

    public function insert(string $productId, int $warehouseId, string $quantity, string $holderType, string $holderId, \DateTimeImmutable $expiresAt): string;

    public function setQuantity(string $id, string $quantity, \DateTimeImmutable $expiresAt): void;

    /** @param 'consumed'|'released'|'expired' $status */
    public function resolve(string $id, string $status): void;

    /** @return list<array<string, mixed>> active reservations past their expiry, oldest first */
    public function due(\DateTimeImmutable $now, int $limit): array;
}
