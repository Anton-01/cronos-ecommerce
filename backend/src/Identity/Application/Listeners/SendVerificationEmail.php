<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Listeners;

use Cronos\Identity\Domain\Events\VerificationEmailRequested;
use Cronos\Identity\Domain\Ports\EmailVerificationLinks;
use Cronos\Identity\Domain\Ports\IdentityNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendVerificationEmail implements ShouldQueue
{
    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function __construct(
        private readonly EmailVerificationLinks $links,
        private readonly IdentityNotifier $notifier,
    ) {}

    public function handle(VerificationEmailRequested $event): void
    {
        $this->notifier->sendEmailVerification($event->email, $this->links->make($event->userId, $event->email));
    }
}
