<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class SocialAccountModel extends Model implements AuditableContract
{
    use Auditable;
    use HasUlids;

    protected $table = 'social_accounts';

    protected $guarded = [];
}
