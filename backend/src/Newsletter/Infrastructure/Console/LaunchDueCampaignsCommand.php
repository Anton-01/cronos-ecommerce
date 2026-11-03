<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure\Console;

use Cronos\Newsletter\Application\CampaignService;
use Cronos\Newsletter\Domain\Events\CampaignReady;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Console\Command;

final class LaunchDueCampaignsCommand extends Command
{
    protected $signature = 'newsletter:launch-due';

    protected $description = 'Launch the scheduled campaigns whose time has come, and re-queue batches of sending campaigns that were left unfinished';

    public function handle(CampaignService $campaigns, DomainEventDispatcher $events): int
    {
        $launched = $campaigns->launchDue();
        // Safety net: a worker that died mid-campaign leaves deliveries `queued`; announce them again (batches already sent are skipped).
        foreach ($campaigns->list('sending') as $c) {
            // Only campaigns well past the time a normal send takes: a running one has its batches queued already.
            $c['counts']['queued'] > 0 && $c['started_at'] !== null && strtotime((string) $c['started_at']) < now()->getTimestamp() - 1800 && $events->dispatch(new CampaignReady($c['id']));
        }
        $this->info("Launched {$launched} campaign(s).");

        return self::SUCCESS;
    }
}
