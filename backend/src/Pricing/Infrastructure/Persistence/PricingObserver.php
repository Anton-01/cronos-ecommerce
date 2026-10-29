<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Persistence;

use Cronos\Pricing\Domain\Ports\PricingCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Any Eloquent write to pricing reference data (our handlers, tinker, seeders) drops the cached snapshot after commit. */
final class PricingObserver
{
    public function __construct(private readonly PricingCatalog $catalog) {}

    public function saved(Model $model): void
    {
        $this->purge();
    }

    public function deleted(Model $model): void
    {
        $this->purge();
    }

    private function purge(): void
    {
        DB::afterCommit(fn () => $this->catalog->forget());
    }
}
