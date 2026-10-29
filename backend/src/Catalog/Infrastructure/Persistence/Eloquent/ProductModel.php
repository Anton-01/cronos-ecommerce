<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence\Eloquent;

use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class ProductModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'products';

    protected $guarded = [];

    /** @var list<string> Written by the inventory ledger, not a catalogue edit. */
    protected $auditExclude = ['stock_quantity'];

    protected function casts(): array
    {
        return [
            'dimensions' => 'array',
            'sell_by_cut' => 'boolean',
            'sell_by_roll' => 'boolean',
            'manage_stock' => 'boolean',
            'allow_backorder' => 'boolean',
            'is_customizable' => 'boolean',
            'published_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function variations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslationModel::class, 'product_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CategoryModel::class, 'product_categories', 'product_id', 'category_id')->withPivot(['is_primary', 'position']);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TagModel::class, 'product_tag', 'product_id', 'tag_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValueModel::class, 'product_attribute_values', 'product_id', 'attribute_value_id');
    }

    /** Gallery images attached through the Media Library (collection "gallery", ordered). */
    public function gallery(): MorphMany
    {
        return $this->morphMany(MediaModel::class, 'owner', 'model_type', 'model_id')->where('collection_name', 'gallery')->orderBy('order_column');
    }

    public function translation(?string $locale = null): ?ProductTranslationModel
    {
        return $this->translations->firstWhere('locale', $locale ?? config('app.locale_default'))
            ?? $this->translations->firstWhere('locale', config('app.locale_default'))
            ?? $this->translations->first();
    }

    public function getNameAttribute(): ?string
    {
        return $this->translation()?->name ?? $this->parent?->translation()?->name;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        return $query->where(fn (Builder $q) => $q
            ->where('sku', 'ilike', $like)
            ->orWhereHas('translations', fn (Builder $t) => $t->where('name', 'ilike', $like)));
    }
}
