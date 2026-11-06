<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/** Changes are audited like every other catalogue entity: who changed what, from where. */
class ProductSpecModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'product_specs';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['labels' => 'array', 'meta' => 'array', 'names' => 'array', 'sales' => 'array', 'required_fields' => 'array', 'variant_attribute_ids' => 'array', 'product_attribute_ids' => 'array', 'specs' => 'array', 'states' => 'array', 'is_active' => 'boolean'];
    }
}
