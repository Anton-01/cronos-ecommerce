<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Persistence\Eloquent;

use Cronos\Shared\Infrastructure\Persistence\StableOrderScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Permission\Traits\HasRoles;

#[ScopedBy([StableOrderScope::class])]
class UserModel extends Authenticatable implements AuditableContract
{
    use Auditable;
    use HasApiTokens;
    use HasRoles;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'users';

    protected string $guard_name = 'web';

    protected $fillable = ['email', 'password', 'is_active', 'google_id'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string> Secrets must never reach the audit trail. */
    protected $auditExclude = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> */
    public function getAllPermissionNames(): array
    {
        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $term === null || $term === ''
            ? $query
            : $query->where('email', 'ilike', '%'.addcslashes($term, '%_\\').'%');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfileModel::class, 'user_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddressModel::class, 'user_id');
    }
}
