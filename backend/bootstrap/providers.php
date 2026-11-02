<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Cronos\Appearance\Infrastructure\AppearanceServiceProvider;
use Cronos\Audit\Infrastructure\AuditServiceProvider;
use Cronos\Catalog\Infrastructure\CatalogServiceProvider;
use Cronos\Checkout\Infrastructure\CheckoutServiceProvider;
use Cronos\CustomOrders\Infrastructure\CustomOrdersServiceProvider;
use Cronos\Fraud\Infrastructure\FraudServiceProvider;
use Cronos\Identity\Infrastructure\IdentityServiceProvider;
use Cronos\Inventory\Infrastructure\InventoryServiceProvider;
use Cronos\Markets\Infrastructure\MarketsServiceProvider;
use Cronos\Media\Infrastructure\MediaServiceProvider;
use Cronos\Notifications\Infrastructure\NotificationsServiceProvider;
use Cronos\Orders\Infrastructure\OrdersServiceProvider;
use Cronos\Payments\Infrastructure\PaymentsServiceProvider;
use Cronos\Pricing\Infrastructure\PricingServiceProvider;
use Cronos\Promotions\Infrastructure\PromotionsServiceProvider;
use Cronos\Reports\Infrastructure\ReportsServiceProvider;
use Cronos\Settings\Infrastructure\SettingsServiceProvider;
use Cronos\Shared\Infrastructure\SharedServiceProvider;
use Cronos\Shipping\Infrastructure\ShippingServiceProvider;
use Cronos\Wishlist\Infrastructure\WishlistServiceProvider;

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
    MarketsServiceProvider::class,
    AppearanceServiceProvider::class,
    PaymentsServiceProvider::class,
    InventoryServiceProvider::class,
    PromotionsServiceProvider::class,
    FraudServiceProvider::class,
    CheckoutServiceProvider::class,
    OrdersServiceProvider::class,
    ShippingServiceProvider::class,
    CustomOrdersServiceProvider::class,
    NotificationsServiceProvider::class,
    ReportsServiceProvider::class,
    WishlistServiceProvider::class,
];
