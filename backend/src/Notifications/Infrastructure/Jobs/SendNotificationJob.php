<?php

declare(strict_types=1);

namespace Cronos\Notifications\Infrastructure\Jobs;

use Cronos\Notifications\Domain\Ports\NotificationLog;
use Cronos\Notifications\Infrastructure\Mail\ShopMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Delivers one logged notification. Retries with backoff; the log records every attempt and the final outcome. */
final class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 7200];
    }

    public function __construct(public readonly string $notificationId)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(NotificationLog $log): void
    {
        $row = $log->find($this->notificationId);
        if ($row === null || $row['status'] === 'sent' || $row['status'] === 'skipped') {
            return; // already delivered (a sweep and a retry can race)
        }
        $log->update($row['id'], ['attempts' => $row['attempts'] + 1]);

        try {
            Mail::to($row['email'])->send(new ShopMail($row['template'], $row['locale'], $row['data']));
        } catch (Throwable $e) {
            $log->update($row['id'], ['last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000)]);
            throw $e;
        }
        $log->update($row['id'], ['status' => 'sent', 'sent_at' => now(), 'last_error' => null]);
    }

    public function failed(Throwable $e): void
    {
        app(NotificationLog::class)->update($this->notificationId, ['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
