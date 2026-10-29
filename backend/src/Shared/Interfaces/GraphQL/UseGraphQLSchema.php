<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Closure;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\GraphQL;
use Nuwave\Lighthouse\Schema\AST\ASTBuilder;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Nuwave\Lighthouse\Schema\Source\SchemaSourceProvider;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects which SDL a route serves (ADR-03: separate storefront/admin schemas).
 * Lighthouse keeps its schema graph in singletons, so they are reset whenever
 * the selected schema changes to keep long-lived workers correct.
 */
final class UseGraphQLSchema
{
    private const SCHEMAS = ['storefront' => 'schema.graphql', 'admin' => 'admin.graphql'];

    private static ?string $active = null;

    public function handle(Request $request, Closure $next, string $schema = 'storefront'): Response
    {
        if (self::$active !== $schema) {
            config([
                'lighthouse.schema_path' => base_path('graphql/'.self::SCHEMAS[$schema]),
                'lighthouse.schema_cache.path' => base_path("bootstrap/cache/lighthouse-schema-{$schema}.php"),
            ]);

            foreach ([GraphQL::class, SchemaSourceProvider::class, ASTBuilder::class, SchemaBuilder::class, DirectiveLocator::class, TypeRegistry::class] as $abstract) {
                app()->forgetInstance($abstract);
            }

            self::$active = $schema;
        }

        return $next($request);
    }

    /** For tests: force the next request to rebuild the schema graph. */
    public static function reset(): void
    {
        self::$active = null;
    }
}
