<?php

declare(strict_types=1);

namespace Cronos\Audit\Infrastructure\Persistence;

use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * Append-only audit row: there is no updated_at column and the database
 * rejects UPDATE/DELETE/TRUNCATE through triggers.
 */
class Audit extends BaseAudit
{
    public const UPDATED_AT = null;
}
