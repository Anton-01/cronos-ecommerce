<?php

declare(strict_types=1);

namespace Cronos\Media\Interfaces\GraphQL\Mutations;

use Cronos\Media\Application\Commands\UploadMedia;
use Cronos\Media\Application\Handlers\UploadMediaHandler;
use Cronos\Media\Domain\Exceptions\InvalidImage;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Illuminate\Http\UploadedFile;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UploadMediaMutation
{
    public function __construct(private readonly UploadMediaHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): MediaModel
    {
        /** @var UploadedFile $file */
        $file = $args['file'];

        // PHP already rejected oversize/partial uploads; surface that as a clean domain error.
        $file->isValid() && $file->getRealPath() !== false || throw new InvalidImage('The upload failed or was interrupted.');

        $record = ($this->handler)(new UploadMedia(
            path: $file->getRealPath(),
            clientName: $file->getClientOriginalName(),
            collection: $args['collection'] ?? 'library',
            uploadedBy: (string) $context->user()->getAuthIdentifier(),
        ));

        return MediaModel::query()->findOrFail($record->id);
    }
}
