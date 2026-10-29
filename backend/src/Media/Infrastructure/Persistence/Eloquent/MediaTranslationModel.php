<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class MediaTranslationModel extends Model
{
    protected $table = 'media_translations';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];
}
