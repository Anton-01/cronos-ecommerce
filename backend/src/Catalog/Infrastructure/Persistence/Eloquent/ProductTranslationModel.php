<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** Composite primary key: rows are written through the repositories, never updated by Eloquent. */
class ProductTranslationModel extends Model
{
    protected $table = 'product_translations';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];
}
