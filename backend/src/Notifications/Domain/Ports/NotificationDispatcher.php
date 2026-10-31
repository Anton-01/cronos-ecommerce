<?php

declare(strict_types=1);

namespace Cronos\Notifications\Domain\Ports;

interface NotificationDispatcher
{
    /** Hands a logged notification to the delivery queue. */
    public function dispatch(string $notificationId): void;
}
