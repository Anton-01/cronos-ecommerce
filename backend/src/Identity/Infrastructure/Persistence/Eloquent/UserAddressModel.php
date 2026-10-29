<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class UserAddressModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'user_addresses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
