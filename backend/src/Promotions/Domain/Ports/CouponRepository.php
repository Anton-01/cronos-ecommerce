<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\Ports;

interface CouponRepository
{
    /** @return array<string, mixed>|null row incl. `currency` (ISO code) and `restrictions` (array) */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array;

    /** @return list<array<string, mixed>> newest first */
    public function search(?string $term, int $limit = 100): array;

    /** @param array<string, mixed> $data @return string new id */
    public function create(array $data): string;

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void;

    public function delete(string $id): void;

    public function codeTaken(string $code, ?string $exceptId = null): bool;

    public function timesUsedBy(string $couponId, ?string $userId): int;

    /**
     * Records one redemption inside a transaction that locks the coupon row, re-checking the limits so two
     * simultaneous orders cannot both take the last use. Idempotent per (coupon, order).
     *
     * @return bool false when the limits were hit in the meantime
     */
    public function redeem(string $couponId, ?string $userId, ?string $orderId, string $amount, string $currency): bool;
}
