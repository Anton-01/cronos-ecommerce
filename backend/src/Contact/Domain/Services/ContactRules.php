<?php

declare(strict_types=1);

namespace Cronos\Contact\Domain\Services;

use Cronos\Contact\Domain\Exceptions\ContactError;

/** What a contact message must look like and which cheap signals make it suspicious. Pure: no database, no HTTP. */
final class ContactRules
{
    /**
     * @param  array<string, mixed>  $in  name, email, phone, topic, order_number, message
     * @param  list<string>  $topics
     * @return array{name: string, email: string, phone: ?string, topic: string, order_number: ?string, message: string}
     */
    public static function normalize(array $in, array $topics): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        mb_strlen($name) < 2 && $errors['name'] = 'Escribe tu nombre.';
        mb_strlen($name) > 120 && $errors['name'] = 'Máximo 120 caracteres.';
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) && $errors['email'] = 'Escribe un correo válido.';
        $phone = trim((string) ($in['phone'] ?? ''));
        $phone !== '' && preg_match('/^[0-9+\-().\s]{7,32}$/', $phone) !== 1 && $errors['phone'] = 'Teléfono no válido.';
        $topic = (string) ($in['topic'] ?? 'general');
        in_array($topic, $topics, true) || $errors['topic'] = 'Tema no válido.';
        $order = strtoupper(trim((string) ($in['order_number'] ?? '')));
        $order !== '' && preg_match('/^[A-Z0-9\-]{3,40}$/', $order) !== 1 && $errors['order_number'] = 'Número de pedido no válido.';
        $message = trim(str_replace("\r\n", "\n", (string) ($in['message'] ?? '')));
        mb_strlen($message) < 10 && $errors['message'] = 'Cuéntanos un poco más (mínimo 10 caracteres).';
        mb_strlen($message) > 4000 && $errors['message'] = 'Máximo 4000 caracteres.';
        $errors === [] || throw new ContactError('The message is not valid.', 'INVALID_MESSAGE', $errors);

        return ['name' => $name, 'email' => $email, 'phone' => $phone === '' ? null : $phone, 'topic' => $topic, 'order_number' => $order === '' ? null : $order, 'message' => $message];
    }

    /**
     * Cheap, explainable signals. None of them proves spam alone, so each one is only recorded; the service decides what to do with the list.
     *
     * @param  array{name: string, email: string, message: string}  $m
     * @return list<string>
     */
    public static function spamSignals(array $m, int $maxLinks): array
    {
        $signals = [];
        preg_match_all('~https?://|www\.~i', $m['message'], $links);
        count($links[0]) > $maxLinks && $signals[] = 'many_links';
        // A name that is a link, or has no letters at all, is not a name.
        (preg_match('~https?://|www\.~i', $m['name']) === 1 || preg_match('/\p{L}/u', $m['name']) !== 1) && $signals[] = 'bad_name';
        // Shouting or a wall of one repeated character.
        preg_match('/(.)\1{14,}/su', $m['message']) === 1 && $signals[] = 'repeated_characters';
        // Scripts in other alphabets than the shop speaks are a common bot tell; only flagged when the text has no Latin letters at all.
        preg_match('/\p{Latin}/u', $m['message']) !== 1 && preg_match('/[\p{Cyrillic}\p{Han}\p{Arabic}]/u', $m['message']) === 1 && $signals[] = 'unexpected_script';
        // Typical pitch from SEO / casino / crypto bots (not a ban list: it only adds a signal).
        preg_match('/\b(seo services|backlinks?|casino|viagra|crypto ?(?:invest|giveaway)|loan offer|bitcoin)\b/i', $m['message']) === 1 && $signals[] = 'spam_phrase';

        return $signals;
    }
}
