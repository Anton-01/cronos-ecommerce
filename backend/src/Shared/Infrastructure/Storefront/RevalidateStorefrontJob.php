<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Storefront;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Tells the storefront which cached tags are stale. Retried with a growing pause; a storefront that stays down is refreshed by its own time limits. */
final class RevalidateStorefrontJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param list<string> $tags */
    public function __construct(public readonly array $tags)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(): void
    {
        $response = Http::withHeaders(['X-Revalidate-Secret' => (string) config('storefront.revalidate_secret')])->timeout(5)
            ->post((string) config('storefront.revalidate_url'), ['tags' => $this->tags]);
        $response->successful() || throw new \RuntimeException("Storefront answered {$response->status()} to the revalidation notice.");
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Storefront revalidation gave up', ['tags' => $this->tags, 'error' => $e->getMessage()]);
    }
}
