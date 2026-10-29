<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\UpdateProfile;
use Cronos\Identity\Application\Handlers\UpdateProfileHandler;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdateMyProfileMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly UpdateProfileHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): UserModel
    {
        $attributes = collect($args['input'])->mapWithKeys(fn ($v, $k) => [Str::snake($k) => $v])->all();
        $userId = (string) $context->user()->getAuthIdentifier();

        ($this->handler)(new UpdateProfile($userId, $attributes));

        return UserModel::query()->findOrFail($userId);
    }
}
