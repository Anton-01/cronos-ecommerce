<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure;

use Cronos\Payments\Domain\Events\PaymentNeedsReview;
use Cronos\Payments\Domain\Ports\CircuitBreaker;
use Cronos\Payments\Domain\Ports\GatewayFactory;
use Cronos\Payments\Domain\Ports\Outbox;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\Ports\WebhookInbox;
use Cronos\Payments\Infrastructure\Console\RelayOutboxCommand;
use Cronos\Payments\Infrastructure\Console\SweepPaymentsCommand;
use Cronos\Payments\Infrastructure\Persistence\DatabaseOutbox;
use Cronos\Payments\Infrastructure\Persistence\DatabasePaymentRepository;
use Cronos\Payments\Infrastructure\Persistence\DatabaseWebhookInbox;
use Cronos\Payments\Infrastructure\Persistence\EloquentProviderRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ProviderRepository::class => EloquentProviderRepository::class,
        PaymentRepository::class => DatabasePaymentRepository::class,
        WebhookInbox::class => DatabaseWebhookInbox::class,
        Outbox::class => DatabaseOutbox::class,
        GatewayFactory::class => LaravelGatewayFactory::class,
        CircuitBreaker::class => CacheCircuitBreaker::class,
    ];

    public function boot(): void
    {
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // Anything that needs a person (late money, amount mismatch, a dispute) must be loud, not just a row in a table.
        Event::listen(PaymentNeedsReview::class, fn (PaymentNeedsReview $e) => Log::critical('Payment needs review', ['payment' => $e->paymentId, 'payable' => $e->payableType.':'.$e->payableId, 'reason' => $e->reason]));

        if ($this->app->runningInConsole()) {
            $this->commands([SweepPaymentsCommand::class, RelayOutboxCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('payments:sweep')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('payments:sweep --reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('outbox:relay')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
