<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class CategoryModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'depth' => 'integer', 'position' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslationModel::class, 'category_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(ProductModel::class, 'product_categories', 'category_id', 'product_id');
    }

    public function translation(?string $locale = null): ?CategoryTranslationModel
    {
        return $this->translations->firstWhere('locale', $locale ?? config('app.locale_default'))
            ?? $this->translations->firstWhere('locale', config('app.locale_default'))
            ?? $this->translations->first();
    }

    public function getNameAttribute(): ?string
    {
        return $this->translation()?->name;
    }
}
