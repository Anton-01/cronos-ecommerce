<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\UpdateAvatar;
use Cronos\Identity\Application\Handlers\UpdateAvatarHandler;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Media\Domain\Exceptions\InvalidImage;
use Illuminate\Http\UploadedFile;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdateMyAvatarMutation
{
    public function __construct(private readonly UpdateAvatarHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): UserModel
    {
        /** @var UploadedFile $file */
        $file = $args['file'];
        $file->isValid() && $file->getRealPath() !== false || throw new InvalidImage('The upload failed or was interrupted.');

        $userId = (string) $context->user()->getAuthIdentifier();
        ($this->handler)(new UpdateAvatar($userId, $file->getRealPath(), $file->getClientOriginalName()));

        return UserModel::query()->findOrFail($userId);
    }
}
