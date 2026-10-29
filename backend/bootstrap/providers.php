<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Cronos\Shared\Infrastructure\SharedServiceProvider::class,
    Cronos\Identity\Infrastructure\IdentityServiceProvider::class,
    Cronos\Media\Infrastructure\MediaServiceProvider::class,
    Cronos\Settings\Infrastructure\SettingsServiceProvider::class,
    Cronos\Catalog\Infrastructure\CatalogServiceProvider::class,
];
