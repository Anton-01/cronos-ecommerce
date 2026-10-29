<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class TaxClassModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'tax_classes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
