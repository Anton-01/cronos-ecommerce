<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\Console;

use Cronos\Shared\Interfaces\GraphQL\UseGraphQLSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/** Prints the fully resolved SDL of one endpoint so front-end codegen can consume it. */
final class ExportGraphQLSchemaCommand extends Command
{
    protected $signature = 'graphql:export {schema=admin : admin|storefront} {--output= : Write to this file instead of stdout}';

    protected $description = 'Export the resolved GraphQL schema of an endpoint';

    public function handle(): int
    {
        $schema = (string) $this->argument('schema');

        if (! in_array($schema, ['admin', 'storefront'], true)) {
            $this->error('Schema must be "admin" or "storefront".');

            return self::FAILURE;
        }

        // Reuse the request middleware so the CLI and HTTP see the very same schema.
        UseGraphQLSchema::reset();
        (new UseGraphQLSchema)->handle(request(), fn () => response('ok'), $schema);

        $buffer = new BufferedOutput;
        Artisan::call('lighthouse:print-schema', [], $buffer);
        $sdl = $buffer->fetch();

        if ($path = $this->option('output')) {
            File::put($path, $sdl);
            $this->info("Schema written to {$path}");
        } else {
            $this->getOutput()->write($sdl);
        }

        return self::SUCCESS;
    }
}
