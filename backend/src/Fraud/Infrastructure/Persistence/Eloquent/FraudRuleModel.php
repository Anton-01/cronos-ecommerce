<?php

declare(strict_types=1);

namespace Cronos\Fraud\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[ScopedBy([StableOrderScope::class])]
class FraudRuleModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;

    protected $table = 'fraud_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array', 'is_active' => 'boolean', 'score' => 'integer'];
    }
}
