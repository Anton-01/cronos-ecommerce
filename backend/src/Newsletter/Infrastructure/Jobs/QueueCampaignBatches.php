<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure\Jobs;

use Cronos\Newsletter\Application\CampaignService;
use Cronos\Newsletter\Domain\Events\CampaignReady;

/** One job per batch, a second apart: Resend's default limit is two requests per second and a campaign must not trip it. */
final class QueueCampaignBatches
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function handle(CampaignReady $e): void
    {
        $gap = (int) config('newsletter.seconds_between_batches');
        foreach ($this->campaigns->pendingBatches($e->campaignId) as $i => $batch) {
            SendCampaignBatchJob::dispatch($e->campaignId, $batch)->delay($i * $gap)->afterCommit();
        }
    }
}
