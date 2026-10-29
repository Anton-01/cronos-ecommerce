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
class TagModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'tags';

    protected $guarded = [];

    public function translations(): HasMany
    {
        return $this->hasMany(TagTranslationModel::class, 'tag_id');
    }

    public function getNameAttribute(): ?string
    {
        return ($this->translations->firstWhere('locale', config('app.locale_default')) ?? $this->translations->first())?->name;
    }
}
