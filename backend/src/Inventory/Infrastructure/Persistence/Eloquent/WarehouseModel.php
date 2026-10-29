<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class WarehouseModel extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'warehouses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
