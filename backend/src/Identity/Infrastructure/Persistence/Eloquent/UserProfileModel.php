<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Persistence\Eloquent;

use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class UserProfileModel extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['birthdate' => 'date', 'marketing_opt_in' => 'boolean', 'preferences' => 'array'];
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(MediaModel::class, 'avatar_media_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }
}
