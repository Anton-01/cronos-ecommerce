<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Persistence;

use Cronos\Identity\Domain\Ports\UserRepository;
use Cronos\Identity\Domain\ValueObjects\SocialProfile;
use Cronos\Identity\Domain\ValueObjects\UserRecord;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\SocialAccountModel;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserProfileModel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

final class EloquentUserRepository implements UserRepository
{
    public const DEFAULT_ROLE = 'customer';

    public function findById(string $id): ?UserRecord
    {
        return $this->toRecord(UserModel::query()->find($id));
    }

    public function findByEmail(string $email): ?UserRecord
    {
        return $this->toRecord(UserModel::query()->where('email', $email)->first());
    }

    public function findBySocialAccount(string $provider, string $providerUserId): ?UserRecord
    {
        $account = SocialAccountModel::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        return $account ? $this->findById($account->user_id) : null;
    }

    public function create(string $email, ?string $passwordHash, array $profile, bool $emailVerified = false): UserRecord
    {
        return DB::transaction(function () use ($email, $passwordHash, $profile, $emailVerified): UserRecord {
            $user = new UserModel(['email' => $email, 'password' => $passwordHash, 'is_active' => true]);
            $user->email_verified_at = $emailVerified ? now() : null;
            $user->save();

            UserProfileModel::query()->create([
                'user_id' => $user->id,
                'first_name' => $profile['first_name'] ?? null,
                'last_name' => $profile['last_name'] ?? null,
                'locale' => $profile['locale'] ?? config('app.locale_default', 'es-MX'),
                'country_code' => $profile['country_code'] ?? null,
            ]);

            return $this->toRecord($user->refresh());
        });
    }

    public function linkSocialAccount(string $userId, SocialProfile $profile): void
    {
        SocialAccountModel::query()->updateOrCreate(
            ['provider' => $profile->provider, 'provider_user_id' => $profile->providerUserId],
            ['user_id' => $userId, 'provider_email' => $profile->email, 'avatar_url' => $profile->avatarUrl],
        );

        if ($profile->provider === 'google') {
            UserModel::query()->whereKey($userId)->update(['google_id' => $profile->providerUserId]);
        }
    }

    public function markEmailVerified(string $userId): void
    {
        $user = UserModel::query()->findOrFail($userId);
        $user->email_verified_at = now();
        $user->save();
    }

    public function passwordHashOf(string $userId): ?string
    {
        return UserModel::query()->whereKey($userId)->value('password');
    }

    public function updatePasswordHash(string $userId, string $hash): void
    {
        $user = UserModel::query()->findOrFail($userId);
        $user->password = $hash;
        $user->save();
    }

    public function recordLogin(string $userId, ?string $ip): void
    {
        // Bypass model events: a login timestamp is not an auditable business change.
        UserModel::withoutEvents(fn () => UserModel::query()->whereKey($userId)->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]));
    }

    public function updateProfile(string $userId, array $attributes): void
    {
        $allowed = ['first_name', 'last_name', 'phone', 'birthdate', 'locale', 'country_code', 'marketing_opt_in'];

        UserProfileModel::query()->firstOrCreate(['user_id' => $userId])->fill(array_intersect_key($attributes, array_flip($allowed)))->save();
    }

    public function setActive(string $userId, bool $active): void
    {
        $user = UserModel::query()->findOrFail($userId);
        $user->is_active = $active;
        $user->save();
    }

    public function syncRoles(string $userId, array $roles): void
    {
        UserModel::query()->findOrFail($userId)->syncRoles($roles);
    }

    public function canAccessBackoffice(string $userId): bool
    {
        $user = UserModel::query()->find($userId);

        return $user !== null
            && Permission::query()->where('name', 'admin.access')->exists()
            && $user->hasPermissionTo('admin.access');
    }

    public function assignDefaultRole(string $userId): void
    {
        UserModel::query()->findOrFail($userId)->assignRole(self::DEFAULT_ROLE);
    }

    private function toRecord(?UserModel $user): ?UserRecord
    {
        return $user === null ? null : new UserRecord(
            id: $user->id,
            email: $user->email,
            isActive: $user->is_active,
            emailVerified: $user->email_verified_at !== null,
            hasPassword: $user->password !== null,
        );
    }
}
