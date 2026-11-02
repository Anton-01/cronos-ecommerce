<?php

declare(strict_types=1);

namespace Cronos\Contact\Domain\Events;

/** A real message (not spam) was stored. Notifications turns it into the e-mail to the shop and the receipt to the sender; listeners must be idempotent. */
final readonly class ContactMessageReceived
{
    public function __construct(
        public string $id,
        public ?string $marketCode,
        public string $locale,
        public string $name,
        public string $email,
        public ?string $phone,
        public string $topic,
        public ?string $orderNumber,
        public string $message,
        public ?string $ip,
        public ?string $country,
    ) {}
}
