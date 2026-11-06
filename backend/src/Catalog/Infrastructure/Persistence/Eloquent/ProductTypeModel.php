<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/** Changes are audited like every other catalogue entity: who changed what, from where. */
class ProductTypeModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;

    protected $table = 'product_types';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['labels' => 'array', 'meta' => 'array', 'names' => 'array', 'sales' => 'array', 'required_fields' => 'array', 'variant_attribute_ids' => 'array', 'product_attribute_ids' => 'array', 'specs' => 'array', 'states' => 'array', 'is_active' => 'boolean'];
    }
}
