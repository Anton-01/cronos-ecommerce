<?php

declare(strict_types=1);

namespace Cronos\Markets\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class MarketProfileModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'market_profiles';

    protected $primaryKey = 'market_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_fallback' => 'boolean'];
    }
}
