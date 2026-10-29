<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Ports;

interface PaymentRepository
{
    /** Runs the work in a transaction (nesting joins the outer one). */
    public function atomically(callable $work): mixed;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** Same as find but takes a row lock until the transaction ends: all status changes go through this. @return array<string, mixed>|null */
    public function lock(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByKey(string $idempotencyKey): ?array;

    /** @return array<string, mixed>|null */
    public function findByReference(string $provider, string $reference): ?array;

    /** @param array<string, mixed> $payment @return string id */
    public function insert(array $payment): string;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** @param array<string, mixed> $transaction @return bool false when the same webhook fact was already written */
    public function addTransaction(array $transaction): bool;

    /** @return list<array<string, mixed>> oldest first */
    public function transactions(string $paymentId): array;

    /** @return list<array<string, mixed>> */
    public function forPayable(string $payableType, string $payableId): array;

    /** @return list<array<string, mixed>> payments still open and older than the cutoff, oldest first */
    public function openOlderThan(\DateTimeImmutable $cutoff, int $limit): array;

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function search(?string $status, ?string $provider, ?string $mode, ?string $term, bool $anomalies, int $limit, int $offset): array;

    /** @return array<string, mixed>|null */
    public function findRefundByKey(string $key): ?array;

    /** @param array<string, mixed> $refund */
    public function insertRefund(array $refund): string;

    /** @param array<string, mixed> $changes */
    public function updateRefund(string $id, array $changes): void;

    /** @return list<array<string, mixed>> */
    public function refunds(string $paymentId): array;

    /** Records a refund made outside the shop (provider dashboard) unless that provider reference is already known. */
    public function adoptRefund(string $paymentId, string $providerReference, string $amount, string $currency): void;

    /** Sum of refunds that are pending or succeeded (money that is or will be going back). */
    public function committedRefunds(string $paymentId): string;
}
