<?php

declare(strict_types=1);

namespace Cronos\Notifications\Application;

use Cronos\Notifications\Domain\Ports\NotificationDispatcher;
use Cronos\Notifications\Domain\Ports\NotificationLog;
use Cronos\Notifications\Domain\Ports\RecipientDirectory;
use Illuminate\Support\Str;

/**
 * Decides to send a message: writes the log row (idempotent on template + key) and queues the delivery. The log is the source of truth
 * for "did the customer hear about this?", and a sweep re-queues anything stuck.
 */
final class NotificationService
{
    public const TEMPLATES = ['order_placed', 'order_status', 'shipment_created', 'quote_ready', 'custom_order_update', 'ops_alert', 'export_ready', 'contact_received', 'contact_receipt', 'newsletter_confirm', 'newsletter_welcome'];

    public function __construct(private readonly NotificationLog $log, private readonly RecipientDirectory $directory, private readonly NotificationDispatcher $dispatcher) {}

    /**
     * @param  array<string, mixed>  $data  everything the template needs (already formatted: the job renders without touching the database)
     * @return bool false when the same notification was already recorded
     */
    public function send(string $template, string $dedupeKey, string $email, ?string $userId, ?string $marketCode, array $data, ?string $relatedType = null, ?string $relatedId = null, ?string $locale = null): bool
    {
        in_array($template, self::TEMPLATES, true) || throw new \InvalidArgumentException("Unknown notification template [{$template}].");

        // An explicit language (the one the visitor was reading) wins over the account's and the store's.
        $locale ??= $this->directory->localeOf($userId) ?? ($marketCode === null ? null : $this->directory->marketLocale($marketCode)) ?? config('app.locale_default');
        in_array($locale, config('app.supported_locales'), true) || $locale = config('app.locale_default');

        $id = $this->log->record([
            'id' => (string) Str::ulid(), 'template' => $template, 'dedupe_key' => $dedupeKey, 'user_id' => $userId, 'email' => strtolower($email), 'locale' => $locale,
            'related_type' => $relatedType, 'related_id' => $relatedId, 'data' => $data,
        ]);
        if ($id === null) {
            return false;
        }
        $this->dispatcher->dispatch($id);

        return true;
    }
}
