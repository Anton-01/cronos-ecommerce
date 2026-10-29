<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Dependency rules of the hexagonal layout, enforced on every run (stands in for Deptrac, which cannot be installed here):
 *
 *   Domain          -> only its own Domain, other modules' Domain, Shared Domain, brick/math and PHP itself
 *   Application     -> Domain and Application (any module); no Infrastructure, Interfaces, Eloquent, GraphQL or HTTP
 *   Infrastructure  -> never Interfaces
 *   Interfaces      -> never another module's Infrastructure
 */
final class HexagonalRulesTest extends TestCase
{
    /**
     * Application code allowed to use the DB facade. Two only open a transaction / run after commit (the unit of work);
     * DiscountQuoter still reads categories through the query builder, a debt with a port to extract.
     */
    private const APPLICATION_DB_ALLOWLIST = [
        'Promotions/Application/DiscountQuoter.php',
        'Catalog/Application/Listeners/PurgeCatalogCache.php',
        'Settings/Application/Handlers/UpdateSettingsHandler.php',
    ];

    /** @return array<string, list<string>> file (relative to src) => imported classes */
    private function imports(): array
    {
        $root = dirname(__DIR__, 2).'/src';
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/^use\s+([^;{\s]+)(?:\s+as\s+\w+)?;/m', (string) file_get_contents($file->getPathname()), $m);
            $out[ltrim(str_replace($root, '', $file->getPathname()), '/')] = $m[1];
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private function violations(string $layer, callable $forbidden): array
    {
        $found = [];
        foreach ($this->imports() as $file => $uses) {
            if (! preg_match('#^(\w+)/'.$layer.'/#', $file, $m)) {
                continue;
            }
            foreach ($uses as $use) {
                if ($forbidden($use, $m[1], $file)) {
                    $found[] = "{$file} imports {$use}";
                }
            }
        }

        return $found;
    }

    public function test_the_domain_depends_on_nothing_but_domain_code(): void
    {
        $violations = $this->violations('Domain', function (string $use): bool {
            if (str_starts_with($use, 'Cronos\\')) {
                return ! preg_match('/^Cronos\\\\\w+\\\\Domain\\\\/', $use);
            }

            return ! str_starts_with($use, 'Brick\\Math') && ! in_array($use, ['DateTimeImmutable', 'DateTimeInterface', 'DomainException', 'InvalidArgumentException'], true);
        });

        $this->assertSame([], $violations);
    }

    public function test_application_services_do_not_reach_into_infrastructure_or_the_delivery_layer(): void
    {
        $violations = $this->violations('Application', function (string $use, string $module, string $file): bool {
            if (preg_match('/^Cronos\\\\\w+\\\\(Infrastructure|Interfaces)\\\\/', $use)) {
                return true;
            }
            if (preg_match('/^(Nuwave|GraphQL|OwenIt)\\\\|^Illuminate\\\\(Database\\\\Eloquent|Http|Routing)/', $use)) {
                return true;
            }

            return $use === 'Illuminate\\Support\\Facades\\DB' && ! in_array($file, self::APPLICATION_DB_ALLOWLIST, true);
        });

        $this->assertSame([], $violations);
    }

    public function test_infrastructure_never_depends_on_the_delivery_layer(): void
    {
        // Service providers are the composition root: they are the one place that wires delivery classes into the container.
        $violations = $this->violations('Infrastructure', fn (string $use, string $module, string $file): bool => ! str_ends_with($file, 'ServiceProvider.php') && preg_match('/^Cronos\\\\\w+\\\\Interfaces\\\\/', $use) === 1);

        $this->assertSame([], $violations);
    }

    public function test_delivery_code_does_not_use_another_modules_infrastructure_except_for_authorisation_models(): void
    {
        // @can(model: ...) in the schema names Eloquent models, so resolvers may only touch their own module's persistence.
        $violations = $this->violations('Interfaces', fn (string $use, string $module): bool => (bool) preg_match('/^Cronos\\\\(\w+)\\\\Infrastructure\\\\/', $use, $m) && $m[1] !== $module);

        $this->assertSame([], $violations);
    }

    public function test_the_allowlist_is_still_needed(): void
    {
        foreach (self::APPLICATION_DB_ALLOWLIST as $file) {
            $this->assertContains('Illuminate\\Support\\Facades\\DB', $this->imports()[$file] ?? [], "{$file} no longer uses DB: remove it from the allowlist");
        }
    }
}
