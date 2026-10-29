<?php

declare(strict_types=1);

namespace Cronos\Notifications\Infrastructure\Persistence;

use Cronos\Notifications\Domain\Ports\RecipientDirectory;
use Illuminate\Support\Facades\DB;

final class DatabaseRecipientDirectory implements RecipientDirectory
{
    public function localeOf(?string $userId): ?string
    {
        return $userId === null ? null : DB::table('user_profiles')->where('user_id', $userId)->value('locale');
    }

    public function marketLocale(string $marketCode): ?string
    {
        return DB::table('markets')->where('code', strtoupper($marketCode))->value('default_locale');
    }
}
