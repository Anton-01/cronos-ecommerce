<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Events;

/**
 * Something the warehouse team should know. `emailRecipients` are the people of the system who asked for e-mail for this process;
 * the inbox copy is already stored. Listeners must be idempotent (the alert id is the dedupe key).
 */
final readonly class WarehouseAlertRaised
{
    /** @param list<array{user_id: string, email: string}> $emailRecipients */
    public function __construct(
        public string $alertId,
        public string $process,
        public string $severity,
        public string $title,
        public string $detail,
        public array $emailRecipients,
    ) {}
}
