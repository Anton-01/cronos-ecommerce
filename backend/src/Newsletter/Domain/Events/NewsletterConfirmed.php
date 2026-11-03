<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Events;

/** The address is confirmed: from now on (and only now) the shop may write to it. `unsubscribeToken` goes in every message. */
final readonly class NewsletterConfirmed
{
    public function __construct(public string $subscriberId, public string $email, public string $locale, public ?string $marketCode, public string $unsubscribeToken) {}
}
