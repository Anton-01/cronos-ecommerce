<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure;

use Cronos\Newsletter\Domain\Events\CampaignReady;
use Cronos\Newsletter\Domain\Ports\CampaignMailer;
use Cronos\Newsletter\Domain\Ports\CampaignRepository;
use Cronos\Newsletter\Domain\Ports\EmailRenderer;
use Cronos\Newsletter\Domain\Ports\SubscriberRepository;
use Cronos\Newsletter\Infrastructure\Console\LaunchDueCampaignsCommand;
use Cronos\Newsletter\Infrastructure\Jobs\QueueCampaignBatches;
use Cronos\Newsletter\Infrastructure\Persistence\DatabaseCampaignRepository;
use Cronos\Newsletter\Infrastructure\Persistence\DatabaseSubscriberRepository;
use Cronos\Newsletter\Infrastructure\Resend\ResendCampaignMailer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class NewsletterServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        SubscriberRepository::class => DatabaseSubscriberRepository::class,
        CampaignRepository::class => DatabaseCampaignRepository::class,
        CampaignMailer::class => ResendCampaignMailer::class,
        EmailRenderer::class => BladeEmailRenderer::class,
    ];

    public function boot(): void
    {
        Event::listen(CampaignReady::class, [QueueCampaignBatches::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([LaunchDueCampaignsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('newsletter:launch-due')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
