<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Cronos\Catalog\Infrastructure\CatalogServiceProvider;
use Cronos\Identity\Infrastructure\IdentityServiceProvider;
use Cronos\Media\Infrastructure\MediaServiceProvider;
use Cronos\Settings\Infrastructure\SettingsServiceProvider;
use Cronos\Shared\Infrastructure\SharedServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    SharedServiceProvider::class,
    IdentityServiceProvider::class,
    MediaServiceProvider::class,
    SettingsServiceProvider::class,
    CatalogServiceProvider::class,
];
