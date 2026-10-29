<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Cronos\Audit\Infrastructure\AuditServiceProvider;
use Cronos\Catalog\Infrastructure\CatalogServiceProvider;
use Cronos\Fraud\Infrastructure\FraudServiceProvider;
use Cronos\Identity\Infrastructure\IdentityServiceProvider;
use Cronos\Inventory\Infrastructure\InventoryServiceProvider;
use Cronos\Media\Infrastructure\MediaServiceProvider;
use Cronos\Pricing\Infrastructure\PricingServiceProvider;
use Cronos\Promotions\Infrastructure\PromotionsServiceProvider;
use Cronos\Settings\Infrastructure\SettingsServiceProvider;
use Cronos\Shared\Infrastructure\SharedServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    SharedServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    MediaServiceProvider::class,
    SettingsServiceProvider::class,
    CatalogServiceProvider::class,
    PricingServiceProvider::class,
    InventoryServiceProvider::class,
    PromotionsServiceProvider::class,
    FraudServiceProvider::class,
];
