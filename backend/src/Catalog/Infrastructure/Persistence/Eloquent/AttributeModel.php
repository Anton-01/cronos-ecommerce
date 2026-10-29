<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class AttributeModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'attributes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_filterable' => 'boolean', 'position' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AttributeTranslationModel::class, 'attribute_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValueModel::class, 'attribute_id')->orderBy('position');
    }

    public function getNameAttribute(): ?string
    {
        return ($this->translations->firstWhere('locale', config('app.locale_default')) ?? $this->translations->first())?->name;
    }
}
