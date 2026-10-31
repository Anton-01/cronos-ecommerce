<?php

declare(strict_types=1);

namespace Cronos\Notifications\Infrastructure;

use Cronos\Notifications\Domain\Ports\NotificationDispatcher;
use Cronos\Notifications\Infrastructure\Jobs\SendNotificationJob;

final class QueueNotificationDispatcher implements NotificationDispatcher
{
    public function dispatch(string $notificationId): void
    {
        SendNotificationJob::dispatch($notificationId);
    }
}
