<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\Console;

use Cronos\Identity\Domain\Ports\PasswordHasher;
use Cronos\Identity\Domain\Ports\UserRepository;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

final class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create {email} {--role=super-admin} {--password= : Omit to be prompted (recommended)}';

    protected $description = 'Create (or promote) a back-office user';

    public function handle(UserRepository $users, PasswordHasher $hasher): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $role = (string) $this->option('role');

        if (! Role::query()->where('name', $role)->exists()) {
            $this->error("Role [{$role}] does not exist. Run: php artisan db:seed --class=RolesAndPermissionsSeeder");

            return self::FAILURE;
        }

        $user = $users->findByEmail($email);

        if ($user === null) {
            $password = (string) ($this->option('password') ?: $this->secret('Password (min 12 characters)'));

            if (mb_strlen($password) < 12) {
                $this->error('The password must have at least 12 characters.');

                return self::FAILURE;
            }

            $user = $users->create($email, $hasher->hash($password), ['first_name' => 'Admin'], emailVerified: true);
        }

        $users->syncRoles($user->id, [$role]);
        $this->info("{$email} now has the [{$role}] role.");

        return self::SUCCESS;
    }
}
