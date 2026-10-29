<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\AvatarStore;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserProfileModel;
use Cronos\Media\Application\Commands\AttachMedia;
use Cronos\Media\Application\Commands\UploadMedia;
use Cronos\Media\Application\Handlers\AttachMediaHandler;
use Cronos\Media\Application\Handlers\UploadMediaHandler;
use Illuminate\Support\Facades\DB;

/** Anti-corruption layer: Identity talks to the Media module only through here. */
final class MediaLibraryAvatarStore implements AvatarStore
{
    private const COLLECTION = 'avatar';

    public function __construct(
        private readonly UploadMediaHandler $upload,
        private readonly AttachMediaHandler $attach,
    ) {}

    public function replace(string $userId, string $uploadedPath, string $clientName): void
    {
        // Avatars are small raster images only: no PDFs, no GIF animations.
        $media = ($this->upload)(new UploadMedia(
            path: $uploadedPath,
            clientName: $clientName,
            collection: self::COLLECTION,
            uploadedBy: $userId,
            onlyMimes: ['image/jpeg', 'image/png', 'image/webp'],
            maxKb: 5120,
        ));

        DB::transaction(function () use ($userId, $media): void {
            ($this->attach)(new AttachMedia($media->id, UserModel::class, $userId, self::COLLECTION));
            UserProfileModel::query()->firstOrCreate(['user_id' => $userId])->forceFill(['avatar_media_id' => $media->id])->save();
        });
    }
}
