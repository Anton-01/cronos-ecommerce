<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Listeners;

use Cronos\Identity\Domain\Events\PasswordResetRequested;
use Cronos\Identity\Domain\Ports\IdentityNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendPasswordResetEmail implements ShouldQueue
{
    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function __construct(private readonly IdentityNotifier $notifier) {}

    public function handle(PasswordResetRequested $event): void
    {
        $base = rtrim((string) config('app.storefront_url'), '/');
        $url = $base.'/reset-password?'.http_build_query(['email' => $event->email, 'token' => $event->token]);

        $this->notifier->sendPasswordReset($event->email, $url);
    }
}
