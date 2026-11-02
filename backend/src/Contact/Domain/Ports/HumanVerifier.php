<?php

declare(strict_types=1);

namespace Cronos\Contact\Domain\Ports;

interface HumanVerifier
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    /** The service could not be reached: the shop decides what to do (it lets the message through, flagged, and relies on its other defences). */
    public const UNAVAILABLE = 'unavailable';

    /** No check is configured (development). */
    public const SKIPPED = 'skipped';

    public function enabled(): bool;

    /** @return self::PASSED|self::FAILED|self::UNAVAILABLE|self::SKIPPED */
    public function verify(?string $token, ?string $ip): string;
}
