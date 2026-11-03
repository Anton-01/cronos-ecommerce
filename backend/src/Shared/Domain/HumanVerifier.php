<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain;

/** "Is this a person?" for public forms (Cloudflare Turnstile today). Shared by every module with a public form. */
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
    /** @param string $action the form the token was issued for ("contact", "newsletter"): a token solved on another form is refused */
    public function verify(?string $token, ?string $ip, string $action): string;
}
