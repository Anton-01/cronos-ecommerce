<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class AttributeValueModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'attribute_values';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'position' => 'integer'];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(AttributeModel::class, 'attribute_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AttributeValueTranslationModel::class, 'attribute_value_id');
    }

    public function getLabelAttribute(): ?string
    {
        return ($this->translations->firstWhere('locale', config('app.locale_default')) ?? $this->translations->first())?->label;
    }
}
