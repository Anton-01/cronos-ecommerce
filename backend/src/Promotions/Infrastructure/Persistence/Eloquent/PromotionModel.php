<?php

declare(strict_types=1);

namespace Cronos\Promotions\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class PromotionModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'promotions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_stackable' => 'boolean', 'is_active' => 'boolean', 'conditions' => 'array', 'actions' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'priority' => 'integer'];
    }
}
