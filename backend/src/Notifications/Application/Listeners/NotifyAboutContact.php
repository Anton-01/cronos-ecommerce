<?php

declare(strict_types=1);

namespace Cronos\Notifications\Application\Listeners;

use Cronos\Contact\Domain\Events\ContactMessageReceived;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Notifications\Application\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * A visitor wrote through the contact form: the shop gets the message (reply-to is the visitor, so "Reply" answers them) and the visitor gets a
 * receipt with a reference. Both are idempotent through the log's dedupe keys.
 */
final class NotifyAboutContact
{
    public function __construct(private readonly NotificationService $notifications, private readonly MarketProfileRepository $profiles) {}

    public function handle(ContactMessageReceived $e): void
    {
        $profile = $e->marketCode === null ? null : $this->profiles->profile($e->marketCode);
        $reference = strtoupper(substr($e->id, -8));
        // What the visitor wrote is shown inside a Markdown e-mail: it must come out as plain text, never as a link, an image or a heading.
        $quoted = self::quote($e->message);

        $shop = (string) (($profile['ops_email'] ?? null) ?: ($profile['contact_email'] ?? null) ?: config('notifications.ops_email', ''));
        if ($shop === '') {
            Log::warning('A contact message arrived but no store e-mail is configured', ['message' => $e->id, 'market' => $e->marketCode]);
        } else {
            $this->notifications->send('contact_received', "contact:{$e->id}", $shop, null, $e->marketCode, [
                'reply_to' => $e->email, 'reference' => $reference, 'name' => $e->name, 'name_md' => self::plain($e->name), 'email' => $e->email, 'email_md' => self::plain($e->email), 'phone_md' => self::plain($e->phone ?? ''),
                'topic' => $e->topic, 'order_md' => self::plain($e->orderNumber ?? ''), 'market' => $e->marketCode ?? '', 'country' => $e->country ?? '', 'quoted' => $quoted,
            ], 'contact_message', $e->id);
        }

        $this->notifications->send('contact_receipt', "contact-receipt:{$e->id}", $e->email, null, $e->marketCode, [
            'reply_to' => $profile['contact_email'] ?? null, 'reference' => $reference, 'name_md' => self::plain($e->name), 'quoted' => $quoted,
            'shop' => (string) ($profile['trade_name'] ?? config('app.name')), 'shop_md' => self::plain((string) ($profile['trade_name'] ?? config('app.name'))), 'support_hours_md' => self::plain((string) ($profile['support_hours'] ?? '')),
        ], 'contact_message', $e->id, $e->locale);
    }

    /** One line of text with nothing the Markdown renderer could act on. */
    public static function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|>~<&])/u', '\\\\$1', trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /** A multi-line text as a Markdown block quote, every special character escaped and line breaks kept. */
    public static function quote(string $text): string
    {
        $lines = array_map(static fn (string $l): string => '> '.self::plain($l), preg_split('/\R/u', trim($text)) ?: []);

        return implode("  \n", $lines);
    }
}
