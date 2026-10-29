<?php

declare(strict_types=1);

namespace Cronos\Audit\Infrastructure\Persistence;

use Cronos\Shared\Infrastructure\Geo\CurrentClient;
use OwenIt\Auditing\Contracts\Audit as AuditContract;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Drivers\Database;

/** The stock database driver, plus the caller's country and the timezone of their clock, so every trail row says where and when (locally) it happened. */
final class GeoAwareAuditDriver extends Database
{
    public function audit(Auditable $model): ?AuditContract
    {
        $location = app(CurrentClient::class)->location();

        return $model->withSavepointIfNeeded(
            fn () => $model->audits()->getModel()::create($model->toAudit() + ['country_code' => $location->country, 'timezone' => $location->timezone])
        );
    }
}
