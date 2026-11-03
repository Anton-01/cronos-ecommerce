<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure\Jobs;

use Cronos\Newsletter\Application\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One batch of a campaign (up to 100 messages). Retried with growing pauses on rate limits or provider trouble; repeating it never mails anyone twice. */
final class SendCampaignBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly string $campaignId, public readonly int $batch)
    {
        $this->onQueue('campaigns');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600, 900];
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaigns->sendBatch($this->campaignId, $this->batch);
    }

    public function failed(\Throwable $e): void
    {
        // The batch's deliveries stay `queued`: "resume" (or the next launch-due run) picks them up again.
        logger()->error('Campaign batch gave up', ['campaign' => $this->campaignId, 'batch' => $this->batch, 'error' => $e->getMessage()]);
    }
}
