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

    /**
     * `url 320w, url 640w, ...` for <img srcset> (WebP renditions). The uploaded original is NOT a candidate: it is the heavy master copy;
     * a capped, optimised twin of the same width is. Null for documents and while pending.
     */
    public function getSrcsetAttribute(): ?string
    {
        return $this->srcsetFor(false);
    }

    /** Same widths in AVIF, for <source type="image/avif">; null when the server could not encode AVIF. */
    public function getSrcsetAvifAttribute(): ?string
    {
        return $this->srcsetFor(true);
    }

    private function srcsetFor(bool $avif): ?string
    {
        $responsive = (array) ($this->responsive_images ?? []);
        if (! $this->is_image || $responsive === []) {
            return null;
        }
        $disk = Storage::disk($this->disk);
        $wanted = array_filter($responsive, fn (array $r, string $k) => str_contains($k, '@avif') === $avif, ARRAY_FILTER_USE_BOTH);
        if ($wanted === []) {
            return null;
        }
        uasort($wanted, fn (array $a, array $b) => $a['width'] <=> $b['width']);

        return implode(', ', array_map(fn (array $r) => $disk->url($r['path']).' '.$r['width'].'w', array_values($wanted)));
    }

    /** @return list<string> */
    public function getWarningsAttribute(): array
    {
        return array_values((array) ($this->custom_properties['warnings'] ?? []));
    }

    /** @return array{x: float, y: float}|null */
    public function getFocalAttribute(): ?array
    {
        $f = $this->custom_properties['focal'] ?? null;

        return is_array($f) ? ['x' => (float) ($f['x'] ?? 0.5), 'y' => (float) ($f['y'] ?? 0.5)] : null;
    }

    /** @return array{originalBytes: int, servedBytes: int, savedPercent: float}|null */
    public function getOptimizationAttribute(): ?array
    {
        $o = $this->custom_properties['optimization'] ?? null;

        return is_array($o) && isset($o['original_bytes']) ? ['originalBytes' => (int) $o['original_bytes'], 'servedBytes' => (int) $o['served_bytes'], 'savedPercent' => (float) $o['saved_percent']] : null;
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
