<?php

declare(strict_types=1);

namespace Cronos\Fraud\Domain\Ports;

use Cronos\Fraud\Domain\ValueObjects\FraudContext;
use Cronos\Fraud\Domain\ValueObjects\FraudDecision;

interface FraudRepository
{
    /** @return list<array{id: string, name: string, type: string, params: array<string, mixed>, score: int, is_active: bool}> */
    public function rules(): array;

    /** @return array<string, mixed>|null */
    public function findRule(string $id): ?array;

    /** @param array<string, mixed> $data @return string */
    public function createRule(array $data): string;

    /** @param array<string, mixed> $data */
    public function updateRule(string $id, array $data): void;

    public function deleteRule(string $id): void;

    /** Appends to the immutable log of assessments. */
    public function recordCheck(?string $orderId, FraudContext $ctx, FraudDecision $decision): string;

    /** @return list<array<string, mixed>> newest first */
    public function checks(?string $orderId, ?string $decision, int $limit = 50): array;
}
