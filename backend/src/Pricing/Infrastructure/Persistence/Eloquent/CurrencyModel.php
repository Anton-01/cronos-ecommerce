<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class CurrencyModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'currencies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'decimals' => 'integer', 'rate_updated_at' => 'datetime'];
    }
}
