<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class SettingModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;

    protected $table = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        // jsonb: scalars round-trip as JSON (true stays true, "26 chars" stays a string).
        return ['value' => 'json'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SettingTranslationModel::class, 'setting_id');
    }
}
