<?php

declare(strict_types=1);

namespace Cronos\Notifications\Infrastructure;

use Cronos\Contact\Domain\Events\ContactMessageReceived;
use Cronos\CustomOrders\Domain\Events\CustomOrderSubmitted;
use Cronos\CustomOrders\Domain\Events\CustomOrderUpdated;
use Cronos\CustomOrders\Domain\Events\QuoteSent;
use Cronos\Inventory\Domain\Events\WarehouseAlertRaised;
use Cronos\Newsletter\Domain\Events\NewsletterConfirmationRequested;
use Cronos\Newsletter\Domain\Events\NewsletterConfirmed;
use Cronos\Notifications\Application\Listeners\NotifyAboutContact;
use Cronos\Notifications\Application\Listeners\NotifyAboutNewsletter;
use Cronos\Notifications\Application\Listeners\NotifyAboutOrders;
use Cronos\Notifications\Application\Listeners\NotifyAboutWarehouse;
use Cronos\Notifications\Domain\Ports\NotificationDispatcher;
use Cronos\Notifications\Domain\Ports\NotificationLog;
use Cronos\Notifications\Domain\Ports\RecipientDirectory;
use Cronos\Notifications\Infrastructure\Console\SweepNotificationsCommand;
use Cronos\Notifications\Infrastructure\Persistence\DatabaseNotificationLog;
use Cronos\Notifications\Infrastructure\Persistence\DatabaseRecipientDirectory;
use Cronos\Orders\Domain\Events\OrderPlaced;
use Cronos\Orders\Domain\Events\OrderStatusChanged;
use Cronos\Payments\Domain\Events\PaymentNeedsReview;
use Cronos\Shipping\Domain\Events\ShipmentCreated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        NotificationLog::class => DatabaseNotificationLog::class,
        NotificationDispatcher::class => QueueNotificationDispatcher::class,
        RecipientDirectory::class => DatabaseRecipientDirectory::class,
    ];

    public function boot(): void
    {
        Event::listen(OrderPlaced::class, [NotifyAboutOrders::class, 'placed']);
        Event::listen(OrderStatusChanged::class, [NotifyAboutOrders::class, 'statusChanged']);
        Event::listen(ShipmentCreated::class, [NotifyAboutOrders::class, 'shipped']);
        Event::listen(QuoteSent::class, [NotifyAboutOrders::class, 'quoteSent']);
        Event::listen(CustomOrderUpdated::class, [NotifyAboutOrders::class, 'customUpdated']);
        Event::listen(CustomOrderSubmitted::class, [NotifyAboutOrders::class, 'customSubmitted']);
        Event::listen(PaymentNeedsReview::class, [NotifyAboutOrders::class, 'paymentNeedsReview']);
        Event::listen(WarehouseAlertRaised::class, [NotifyAboutWarehouse::class, 'handle']);
        Event::listen(ContactMessageReceived::class, [NotifyAboutContact::class, 'handle']);
        Event::listen(NewsletterConfirmationRequested::class, [NotifyAboutNewsletter::class, 'confirmationRequested']);
        Event::listen(NewsletterConfirmed::class, [NotifyAboutNewsletter::class, 'confirmed']);

        if ($this->app->runningInConsole()) {
            $this->commands([SweepNotificationsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('notifications:sweep')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
