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
        'pricing.view', 'pricing.manage',
        'promotions.view', 'promotions.manage',
        'fraud.view', 'fraud.manage',
        'inventory.view', 'inventory.manage',
        'orders.view', 'orders.manage', 'orders.cancel',
        'markets.view', 'markets.manage',
        'reports.view', 'reports.export',
        'appearance.view', 'appearance.manage', 'appearance.publish',
        'content.view', 'content.manage', 'content.publish',
        'contact.view', 'contact.manage',
        'custom-orders.view', 'custom-orders.manage',
        'payments.view', 'payments.manage', 'payments.refund', 'payments.go-live',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('customer', 'web');
        Role::findOrCreate('support', 'web')->syncPermissions(['admin.access', 'users.view', 'media.view', 'settings.view', 'catalog.view', 'pricing.view', 'inventory.view', 'orders.view', 'custom-orders.view', 'contact.view', 'contact.manage']);
        Role::findOrCreate('admin', 'web')->syncPermissions(
            array_diff(self::PERMISSIONS, ['roles.manage', 'payments.go-live', 'appearance.publish'])
        );
        Role::findOrCreate('super-admin', 'web')->syncPermissions(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
