<?php

declare(strict_types=1);

namespace Cronos\Fraud\Domain\Ports;

/** Short-lived behavioural counters ("attempts from this IP in the last 10 minutes"). */
interface FraudSignals
{
    public const MAX_WINDOW_MINUTES = 1440;

    public function record(string $kind, string $key): void;

    public function count(string $kind, string $key, int $windowMinutes): int;
}
