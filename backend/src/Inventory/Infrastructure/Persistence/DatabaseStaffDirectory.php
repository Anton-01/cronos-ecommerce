<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\StaffDirectory;
use Illuminate\Support\Facades\DB;

/** Staff = an active account that holds at least one role besides "customer". */
final class DatabaseStaffDirectory implements StaffDirectory
{
    private function base()
    {
        return DB::table('users as u')->whereNull('u.deleted_at')->where('u.is_active', true)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
                ->whereColumn('m.model_id', 'u.id')->where('m.model_type', 'like', '%User%')->where('r.name', '<>', 'customer'));
    }

    public function staff(array $userIds): array
    {
        return $userIds === [] ? [] : $this->base()->whereIn('u.id', $userIds)->get(['u.id', 'u.email'])->map(fn ($r) => ['id' => $r->id, 'email' => (string) $r->email])->all();
    }

    public function isStaff(string $userId): bool
    {
        return $this->base()->where('u.id', $userId)->exists();
    }

    public function all(): array
    {
        $users = $this->base()->orderBy('u.email')->get(['u.id', 'u.email']);
        $roles = DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')->whereIn('m.model_id', $users->pluck('id'))->where('r.name', '<>', 'customer')->get(['m.model_id', 'r.name'])->groupBy('model_id');

        return $users->map(fn ($u) => ['id' => $u->id, 'email' => (string) $u->email, 'roles' => $roles->get($u->id, collect())->pluck('name')->all()])->all();
    }
}
