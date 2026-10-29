<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** Permission naming convention: resource.action */
    public const PERMISSIONS = [
        'admin.access',
        'users.view', 'users.update', 'users.assign-roles',
        'roles.view', 'roles.manage',
        'audits.view',
        'media.view', 'media.upload', 'media.update', 'media.delete',
        'settings.view', 'settings.update',
        'catalog.view', 'catalog.manage', 'catalog.publish',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('customer', 'web');
        Role::findOrCreate('support', 'web')->syncPermissions(['admin.access', 'users.view', 'media.view', 'settings.view', 'catalog.view']);
        Role::findOrCreate('admin', 'web')->syncPermissions(
            array_diff(self::PERMISSIONS, ['roles.manage'])
        );
        Role::findOrCreate('super-admin', 'web')->syncPermissions(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
