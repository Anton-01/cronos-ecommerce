<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Services;

use Cronos\Newsletter\Domain\Exceptions\NewsletterError;

final class NewsletterRules
{
    public const SOURCES = ['footer', 'register', 'admin'];

    public static function email(string $raw): string
    {
        $email = strtolower(trim($raw));
        (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) && throw new NewsletterError('Enter a valid e-mail address.', 'INVALID_EMAIL', ['email' => 'Escribe un correo válido.']);

        return $email;
    }

    public static function source(string $source): string
    {
        return in_array($source, ['footer', 'register'], true) ? $source : 'footer'; // "admin" is never accepted from the public form
    }
}
