<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class MediaModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'media';

    protected $guarded = [];

    /** @var list<string> Pipeline bookkeeping is not a business change worth an audit row. */
    protected $auditExclude = ['status', 'failure_reason', 'generated_conversions', 'responsive_images', 'custom_properties', 'width', 'height'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'custom_properties' => 'array',
            'generated_conversions' => 'array',
            'responsive_images' => 'array',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'model_type', 'model_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MediaTranslationModel::class, 'media_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $term === null || $term === ''
            ? $query
            : $query->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%');
    }

    public function scopeInCollection(Builder $query, ?string $collection): Builder
    {
        return $collection === null || $collection === '' ? $query : $query->where('collection_name', $collection);
    }

    /** Filter by broad kind: IMAGE or DOCUMENT. */
    public function scopeOfKind(Builder $query, ?string $kind): Builder
    {
        return match ($kind) {
            'IMAGE' => $query->where('mime_type', 'like', 'image/%'),
            'DOCUMENT' => $query->where('mime_type', 'not like', 'image/%'),
            default => $query,
        };
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->id.'/'.$this->file_name);
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** @return list<array{name: string, url: string, width: int, height: int}> */
    public function conversionList(): array
    {
        $disk = Storage::disk($this->disk);

        $generated = $this->generated_conversions ?? [];

        // jsonb does not preserve key order: follow the configured order so the output is predictable.
        return collect(array_keys((array) config('media.conversions')))
            ->filter(fn (string $name) => isset($generated[$name]))
            ->map(fn (string $name) => ['name' => $name, 'url' => $disk->url($generated[$name]['path']), 'width' => $generated[$name]['width'], 'height' => $generated[$name]['height']])
            ->values()
            ->all();
    }

    /** `url 320w, url 640w, ...` for <img srcset>; the original is the widest candidate. Null for documents and while pending. */
    public function getSrcsetAttribute(): ?string
    {
        $responsive = (array) ($this->responsive_images ?? []);
        if (! $this->is_image || $responsive === []) {
            return null;
        }

        $disk = Storage::disk($this->disk);
        uasort($responsive, fn (array $a, array $b) => $a['width'] <=> $b['width']);
        $candidates = array_map(fn (array $r) => $disk->url($r['path']).' '.$r['width'].'w', array_values($responsive));
        $this->width === null || $candidates[] = $this->url.' '.$this->width.'w';

        return implode(', ', $candidates);
    }

    /** Best small rendition for lists, falling back to the original while conversions are pending. */
    public function getThumbUrlAttribute(): ?string
    {
        if (! $this->is_image) {
            return null;
        }

        foreach ($this->conversionList() as $conversion) {
            if ($conversion['name'] === 'thumb') {
                return $conversion['url'];
            }
        }

        return $this->url;
    }

    public function altFor(string $locale): ?string
    {
        return $this->translations->firstWhere('locale', $locale)?->alt;
    }
}
