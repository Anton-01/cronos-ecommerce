<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Persistence;

use Cronos\Media\Domain\Events\MediaChanged;
use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Media\Domain\ValueObjects\MediaRecord;
use Cronos\Media\Domain\ValueObjects\NewMedia;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaTranslationModel;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Facades\DB;

final class EloquentMediaRepository implements MediaRepository
{
    public function __construct(private readonly DomainEventDispatcher $events) {}

    public function create(NewMedia $media): MediaRecord
    {
        $model = new MediaModel([
            'name' => $media->name,
            'file_name' => $media->fileName,
            'mime_type' => $media->mimeType,
            'disk' => $media->disk,
            'size' => $media->size,
            'checksum_sha256' => $media->checksum,
            'collection_name' => $media->collection,
            'uploaded_by' => $media->uploadedBy,
            'width' => $media->width,
            'height' => $media->height,
            'status' => 'pending',
        ]);
        // The id is chosen by the caller because the stored path is derived from it.
        $model->id = $media->id;
        $model->save();

        $this->events->dispatch(new MediaChanged($model->id));

        return $this->toRecord($model);
    }

    public function find(string $id): ?MediaRecord
    {
        $model = MediaModel::query()->find($id);

        return $model ? $this->toRecord($model) : null;
    }

    public function markProcessing(string $id): void
    {
        $this->quietly(fn () => MediaModel::query()->whereKey($id)->update(['status' => 'processing', 'failure_reason' => null]));
    }

    public function markReady(string $id, ?int $width, ?int $height, array $properties, array $conversions): void
    {
        $this->quietly(fn () => MediaModel::query()->whereKey($id)->update([
            'status' => 'ready',
            'width' => $width,
            'height' => $height,
            'custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'generated_conversions' => json_encode($conversions, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'failure_reason' => null,
        ]));
        $this->events->dispatch(new MediaChanged($id));
    }

    public function markFailed(string $id, string $reason): void
    {
        $this->quietly(fn () => MediaModel::query()->whereKey($id)->update(['status' => 'failed', 'failure_reason' => $reason]));
    }

    public function rename(string $id, string $name): void
    {
        $model = MediaModel::query()->findOrFail($id);
        $model->name = $name;
        $model->save();
        $this->events->dispatch(new MediaChanged($id));
    }

    public function syncTranslations(string $id, array $translations): void
    {
        DB::transaction(function () use ($id, $translations): void {
            foreach ($translations as $locale => $fields) {
                MediaTranslationModel::query()->upsert(
                    [[
                        'media_id' => $id,
                        'locale' => $locale,
                        'alt' => $fields['alt'] ?? null,
                        'title' => $fields['title'] ?? null,
                        'caption' => $fields['caption'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['media_id', 'locale'],
                    ['alt', 'title', 'caption', 'updated_at'],
                );
            }
        });
        $this->events->dispatch(new MediaChanged($id));
    }

    public function attach(string $id, string $ownerType, string $ownerId, string $collection, bool $replaceExisting): void
    {
        DB::transaction(function () use ($id, $ownerType, $ownerId, $collection, $replaceExisting): void {
            if ($replaceExisting) {
                MediaModel::query()
                    ->where(['model_type' => $ownerType, 'model_id' => $ownerId, 'collection_name' => $collection])
                    ->whereKeyNot($id)
                    ->get()
                    ->each->delete();
            }

            $model = MediaModel::query()->findOrFail($id);
            $model->fill(['model_type' => $ownerType, 'model_id' => $ownerId, 'collection_name' => $collection]);
            $model->order_column ??= (int) MediaModel::query()
                ->where(['model_type' => $ownerType, 'model_id' => $ownerId, 'collection_name' => $collection])
                ->max('order_column') + 1;
            $model->save();
        });
        $this->events->dispatch(new MediaChanged($id));
    }

    public function delete(string $id): void
    {
        MediaModel::query()->findOrFail($id)->delete();
        $this->events->dispatch(new MediaChanged($id));
    }

    private function quietly(callable $callback): void
    {
        MediaModel::withoutAuditing($callback);
    }

    private function toRecord(MediaModel $model): MediaRecord
    {
        return new MediaRecord(
            id: $model->id,
            disk: $model->disk,
            path: $model->id.'/'.$model->file_name,
            mimeType: $model->mime_type,
            status: $model->status,
            width: $model->width,
            height: $model->height,
        );
    }
}
