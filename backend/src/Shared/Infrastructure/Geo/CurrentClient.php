<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Geo;

use Cronos\Shared\Domain\ClientContext;
use Cronos\Shared\Domain\Geo\GeoLocation;
use Cronos\Shared\Domain\Geo\GeoLocator;
use Illuminate\Http\Request;

/**
 * The caller of the current request: IP, country and clock (timezone). Resolved once per request, from the IP only.
 * Outside a request (console, queue workers) there is no caller: the configured fallback clock applies.
 */
final class CurrentClient
{
    private ?GeoLocation $location = null;

    private ?int $resolvedFor = null;

    public function __construct(private readonly GeoLocator $locator) {}

    public function location(): GeoLocation
    {
        // Long-lived workers (Octane, tests) reuse this object: a different request means a different caller.
        $current = ($request = $this->request()) === null ? 0 : spl_object_id($request);
        if ($this->location === null || $this->resolvedFor !== $current) {
            $this->location = $this->resolve();
            $this->resolvedFor = $current;
        }

        return $this->location;
    }

    public function context(): ClientContext
    {
        $request = $this->request();
        $location = $this->location();

        return new ClientContext($location->ip, $request?->userAgent(), $location->country, $location->timezone);
    }

    private function resolve(): GeoLocation
    {
        $request = $this->request();
        if ($request === null) {
            return $this->locator->locate(null);
        }

        // A CDN's country header is only believed when the request really came through a trusted proxy.
        $header = config('geo.country_header');
        $hint = $header && $request->isFromTrustedProxy() ? $request->headers->get((string) $header) : null;

        return $this->locator->locate($request->ip(), $hint);
    }

    private function request(): ?Request
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return app()->bound('request') ? app('request') : null;
    }
}
