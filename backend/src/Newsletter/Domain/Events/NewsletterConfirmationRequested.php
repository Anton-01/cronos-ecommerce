<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Events;

/** Someone asked to subscribe (or asked again): they must be sent the link that proves the address is theirs. `attempt` makes each resend its own notification. */
final readonly class NewsletterConfirmationRequested
{
    public function __construct(public string $subscriberId, public string $email, public string $locale, public ?string $marketCode, public string $token, public int $attempt) {}
}
