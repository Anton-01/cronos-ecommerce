<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Ports;

interface CampaignRepository
{
    /** @return list<array<string, mixed>> newest first, each with its delivery counts */
    public function all(?string $status): array;

    /** @return array<string, mixed>|null with counts */
    public function find(string $id): ?array;

    /** @param array<string, mixed> $row */
    public function create(array $row): string;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    public function delete(string $id): void;

    /** @return list<string> ids of scheduled campaigns whose time has come */
    public function dueIds(): array;

    /**
     * Freezes the recipients: one delivery row per confirmed subscriber of the audience, numbered in batches. Safe to call twice (rows are unique per
     * subscriber); returns how many deliveries the campaign has.
     *
     * @param  array{markets: list<string>, locales: list<string>}  $audience
     */
    public function snapshotRecipients(string $campaignId, array $audience, int $batchSize): int;

    /** @return list<int> numbers of the batches that still have queued deliveries */
    public function pendingBatches(string $campaignId): array;

    /** @return list<array<string, mixed>> queued deliveries of one batch, with the subscriber's market */
    public function queuedInBatch(string $campaignId, int $batch): array;

    /** @param list<int|string> $deliveryIds */
    public function markSent(array $deliveryIds, array $providerIds): void;

    /** @param list<int|string> $deliveryIds */
    public function markFailed(array $deliveryIds, string $error): void;

    /** Status of the delivery behind a provider message id; null when we do not know that id. */
    public function setStatusByProviderId(string $providerId, string $status): ?array;

    /** @return array<string, int> */
    public function counts(string $campaignId): array;
}
