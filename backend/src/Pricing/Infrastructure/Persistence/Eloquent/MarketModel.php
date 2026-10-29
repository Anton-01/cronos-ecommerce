<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class MarketModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'markets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prices_include_tax' => 'boolean', 'is_active' => 'boolean'];
    }
}
