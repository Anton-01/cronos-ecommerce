<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Domain\Ports;

interface CustomOrderRepository
{
    public function atomically(callable $work): mixed;

    public function nextNumber(): string;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null row lock */
    public function lock(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByNumber(string $number): ?array;

    /** @param array<string, mixed> $request */
    public function insert(array $request): void;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** @param array<string, mixed> $message */
    public function addMessage(string $requestId, array $message): void;

    /** @return list<array<string, mixed>> */
    public function messages(string $requestId, bool $includeInternal): array;

    /**
     * @param  array<string, mixed>  $quote
     * @param  list<array<string, mixed>>  $lines
     */
    public function insertQuote(array $quote, array $lines): void;

    public function nextQuoteVersion(string $requestId): int;

    /** @return array<string, mixed>|null with lines */
    public function quote(string $quoteId): ?array;

    /** @return array<string, mixed>|null the quote row locked */
    public function lockQuote(string $quoteId): ?array;

    /** @param array<string, mixed> $changes */
    public function updateQuote(string $quoteId, array $changes): void;

    /** @return list<array<string, mixed>> newest version first; drafts only when $includeDrafts */
    public function quotes(string $requestId, bool $includeDrafts): array;

    /** @return list<array<string, mixed>> sent quotes whose validity ended */
    public function expiredQuotes(\DateTimeImmutable $today, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function forUser(string $userId, int $limit, int $offset): array;

    public function countForUser(string $userId): int;

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    public function search(?string $status, ?string $term, ?string $assignedTo, int $limit, int $offset): array;
}
