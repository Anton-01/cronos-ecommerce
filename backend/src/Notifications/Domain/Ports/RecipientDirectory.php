<?php

declare(strict_types=1);

namespace Cronos\Notifications\Domain\Ports;

interface RecipientDirectory
{
    /** The language the account chose, or null for guests and unknown users. */
    public function localeOf(?string $userId): ?string;

    /** Default language of a market (es-MX for MX, en-US for US). */
    public function marketLocale(string $marketCode): ?string;
}
