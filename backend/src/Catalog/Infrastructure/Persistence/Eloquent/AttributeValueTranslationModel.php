<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** Composite primary key: rows are written through the repositories, never updated by Eloquent. */
class AttributeValueTranslationModel extends Model
{
    protected $table = 'attribute_value_translations';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];
}
