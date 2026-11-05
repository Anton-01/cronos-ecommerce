<?php

declare(strict_types=1);

namespace Cronos\Notifications\Application\Listeners;

use Cronos\Inventory\Domain\Events\WarehouseAlertRaised;
use Cronos\Notifications\Application\NotificationService;

/** The warehouse raised an alert and some people asked to hear it by e-mail. One message per person; the log dedupes a repeated delivery. */
final class NotifyAboutWarehouse
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(WarehouseAlertRaised $e): void
    {
        $url = rtrim((string) config('app.admin_url', ''), '/');
        foreach ($e->emailRecipients as $r) {
            $this->notifications->send('warehouse_alert', "warehouse-alert:{$e->alertId}:{$r['user_id']}", $r['email'], $r['user_id'], null, [
                'subject_line' => $e->title, 'title_md' => self::plain($e->title), 'detail_md' => self::plain($e->detail),
                'url' => $url === '' ? null : $url.'/warehouse/alerts',
            ], 'warehouse_alert', $e->alertId);
        }
    }

    /** Names and notes come from people and appear inside a Markdown e-mail: they must stay plain text. */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/([\\`*_{}\[\]()#+\-.!<>|~])/', '\\\\$1', $text);
    }
}
