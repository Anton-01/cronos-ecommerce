<?php

declare(strict_types=1);

namespace Cronos\Fraud\Application;

use Cronos\Fraud\Domain\Ports\FraudRepository;
use Cronos\Fraud\Domain\Ports\FraudSignals;
use Cronos\Fraud\Domain\Services\FraudAssessor;
use Cronos\Fraud\Domain\ValueObjects\FraudContext;
use Cronos\Fraud\Domain\ValueObjects\FraudDecision;
use Cronos\Fraud\Domain\ValueObjects\FraudRule;

/** Scores a checkout attempt, logs the verdict immutably and feeds the behavioural counters. */
final class FraudService
{
    public function __construct(private readonly FraudRepository $repository, private readonly FraudSignals $signals) {}

    public function assess(FraudContext $ctx, ?string $orderId = null): FraudDecision
    {
        $assessor = new FraudAssessor($this->signals, (int) config('fraud.review_at', 50), (int) config('fraud.block_at', 80));
        $rules = array_map(fn (array $r) => new FraudRule($r['id'], $r['name'], $r['type'], $r['params'], $r['score'], $r['is_active']), $this->repository->rules());

        $decision = $assessor->assess($ctx, $rules);
        $this->repository->recordCheck($orderId, $ctx, $decision);

        $ctx->ip === null || $this->signals->record('attempt_ip', $ctx->ip);
        $ctx->email === null || $this->signals->record('attempt_email', strtolower($ctx->email));

        return $decision;
    }

    public function recordFailedPayment(?string $email, ?string $ip): void
    {
        $key = $email === null ? $ip : strtolower($email);
        $key === null || $this->signals->record('failed_payment', $key);
    }
}
