<?php

declare(strict_types=1);

namespace Cronos\Fraud\Domain\ValueObjects;

final class FraudDecision
{
    public const ALLOW = 'allow';

    public const REVIEW = 'review';

    public const BLOCK = 'block';

    /** @param list<array{rule_id: string, rule: string, score: int, detail: string}> $reasons */
    public function __construct(public readonly int $score, public readonly string $decision, public readonly array $reasons) {}
}
