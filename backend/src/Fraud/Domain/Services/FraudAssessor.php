<?php

declare(strict_types=1);

namespace Cronos\Fraud\Domain\Services;

use Cronos\Fraud\Domain\Ports\FraudSignals;
use Cronos\Fraud\Domain\ValueObjects\FraudContext;
use Cronos\Fraud\Domain\ValueObjects\FraudDecision;
use Cronos\Fraud\Domain\ValueObjects\FraudRule;

/** Adds up the score of every rule that fires; the total decides allow / review (a human looks) / block. */
final class FraudAssessor
{
    public function __construct(private readonly FraudSignals $signals, private readonly int $reviewAt = 50, private readonly int $blockAt = 80) {}

    /** @param list<FraudRule> $rules */
    public function assess(FraudContext $ctx, array $rules): FraudDecision
    {
        $reasons = [];
        $score = 0;

        foreach ($rules as $rule) {
            if (! $rule->isActive || ($detail = $this->fires($rule, $ctx)) === null) {
                continue;
            }
            $score += $rule->score;
            $reasons[] = ['rule_id' => $rule->id, 'rule' => $rule->name, 'score' => $rule->score, 'detail' => $detail];
        }

        $score = min(100, $score);

        return new FraudDecision($score, $score >= $this->blockAt ? FraudDecision::BLOCK : ($score >= $this->reviewAt ? FraudDecision::REVIEW : FraudDecision::ALLOW), $reasons);
    }

    private function fires(FraudRule $rule, FraudContext $ctx): ?string
    {
        $p = $rule->params;

        return match ($rule->type) {
            'velocity_ip' => $this->velocity('attempt_ip', $ctx->ip, $p, 'attempts from this IP'),
            'velocity_email' => $this->velocity('attempt_email', $ctx->email === null ? null : strtolower($ctx->email), $p, 'attempts with this email'),
            'failed_payments' => $this->velocity('failed_payment', $ctx->email === null ? $ctx->ip : strtolower($ctx->email), $p, 'failed payments'),
            'amount_over' => isset($p['amounts'][$ctx->total->currency]) && $ctx->total->amount->isGreaterThan($p['amounts'][$ctx->total->currency])
                ? "total {$ctx->total->value()} {$ctx->total->currency} is over {$p['amounts'][$ctx->total->currency]}" : null,
            'country_mismatch' => $ctx->billingCountry !== null && $ctx->shippingCountry !== null && strtoupper($ctx->billingCountry) !== strtoupper($ctx->shippingCountry)
                ? "billing {$ctx->billingCountry} differs from shipping {$ctx->shippingCountry}" : null,
            'high_risk_country' => ($hit = array_intersect($p['countries'] ?? [], array_filter([strtoupper((string) $ctx->billingCountry), strtoupper((string) $ctx->shippingCountry)]))) !== []
                ? 'high-risk country '.implode(',', $hit) : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $p */
    private function velocity(string $kind, ?string $key, array $p, string $label): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $seen = $this->signals->count($kind, $key, (int) $p['window_minutes']);

        return $seen >= (int) $p['max'] ? "{$seen} {$label} in {$p['window_minutes']} min (limit {$p['max']})" : null;
    }
}
