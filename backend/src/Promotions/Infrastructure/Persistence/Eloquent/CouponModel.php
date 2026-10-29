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
class CouponModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'coupons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_stackable' => 'boolean', 'is_active' => 'boolean', 'restrictions' => 'array',
            'starts_at' => 'datetime', 'expires_at' => 'datetime', 'max_uses' => 'integer', 'max_uses_per_user' => 'integer', 'uses_count' => 'integer',
        ];
    }
}
