<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/** Gateway configuration. Credentials are encrypted at rest and never written to the audit trail (only the fact that they changed). */
class PaymentProviderModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'payment_providers';

    protected $guarded = [];

    /** @var list<string> */
    protected $auditExclude = ['credentials_sandbox', 'credentials_live'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean', 'markets' => 'array', 'live_since' => 'datetime',
            'credentials_sandbox' => 'encrypted:array', 'credentials_live' => 'encrypted:array',
        ];
    }
}
