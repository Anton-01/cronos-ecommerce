<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class SettingTranslationModel extends Model
{
    protected $table = 'setting_translations';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];
}
