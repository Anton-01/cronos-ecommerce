<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class TaxRateModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'tax_rates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_compound' => 'boolean', 'priority' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
