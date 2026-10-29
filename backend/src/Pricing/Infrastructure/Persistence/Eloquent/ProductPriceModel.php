<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class ProductPriceModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;

    protected $table = 'product_prices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sale_starts_at' => 'datetime', 'sale_ends_at' => 'datetime'];
    }
}
