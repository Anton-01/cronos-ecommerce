<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Queries;

use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/** Back-office list: parents, simple and grouped products (variations are managed inside their parent). */
final class AdminProductList
{
    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): Builder
    {
        return ProductModel::query()->with('translations')->where('type', '<>', 'variation')
            ->when(empty($args['orderBy']), fn (Builder $q) => $q->orderByDesc('updated_at'));
    }
}
