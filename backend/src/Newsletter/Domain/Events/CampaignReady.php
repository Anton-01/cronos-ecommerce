<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Events;

/** Recipients are frozen and the campaign is going out: the batches must be queued. */
final readonly class CampaignReady
{
    public function __construct(public string $campaignId) {}
}
