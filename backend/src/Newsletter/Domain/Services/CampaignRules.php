<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Services;

use Cronos\Newsletter\Domain\Exceptions\NewsletterError;

final class CampaignRules
{
    /**
     * @param  array<string, array<string, mixed>>  $translations  by locale
     * @param  list<string>  $locales
     * @return array<string, array{subject: string, preheader: string, body_md: string}>
     */
    public static function translations(array $translations, array $locales): array
    {
        $out = [];
        $errors = [];
        foreach ($translations as $locale => $t) {
            if (! in_array($locale, $locales, true)) {
                $errors["translations.{$locale}"] = 'Idioma no soportado.';

                continue;
            }
            $subject = trim(preg_replace('/[\r\n]+/', ' ', (string) ($t['subject'] ?? '')) ?? ''); // a header: never a line break
            $body = trim((string) ($t['body_md'] ?? $t['bodyMd'] ?? ''));
            if ($subject === '' && $body === '') {
                continue; // language left empty on purpose
            }
            $subject === '' && $errors["translations.{$locale}.subject"] = 'El asunto es obligatorio.';
            mb_strlen($subject) > 150 && $errors["translations.{$locale}.subject"] = 'Máximo 150 caracteres.';
            $body === '' && $errors["translations.{$locale}.body"] = 'Escribe el mensaje.';
            strlen($body) > 100000 && $errors["translations.{$locale}.body"] = 'El mensaje es demasiado largo.';
            $out[$locale] = ['subject' => $subject, 'preheader' => mb_substr(trim((string) ($t['preheader'] ?? '')), 0, 200), 'body_md' => $body];
        }
        $errors === [] || throw new NewsletterError('The campaign is not valid.', 'INVALID_CAMPAIGN', $errors);

        return $out;
    }

    /** @param array<string, mixed> $in @return array{markets: list<string>, locales: list<string>} */
    public static function audience(array $in): array
    {
        $clean = static fn (mixed $v): array => array_values(array_unique(array_filter(array_map(static fn ($x) => trim((string) $x), (array) $v))));

        return ['markets' => array_map('strtoupper', $clean($in['markets'] ?? [])), 'locales' => $clean($in['locales'] ?? [])];
    }

    /** @param array<string, mixed> $campaign */
    public static function assertSendable(array $campaign): void
    {
        $campaign['translations'] !== [] || throw new NewsletterError('Write the message before sending it.', 'INVALID_CAMPAIGN', ['translations' => 'Escribe el mensaje en al menos un idioma.']);
        in_array($campaign['status'], ['draft', 'scheduled'], true) || throw new NewsletterError('This campaign was already sent or cancelled.', 'CAMPAIGN_LOCKED');
    }

    /** The subscriber's language, else the default language, else whichever is written. @param array<string, mixed> $translations */
    public static function pick(array $translations, string $locale, string $default): ?string
    {
        foreach ([$locale, $default] as $l) {
            if (isset($translations[$l])) {
                return $l;
            }
        }

        return array_key_first($translations);
    }
}
