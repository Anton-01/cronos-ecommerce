<?php

declare(strict_types=1);

namespace Cronos\Payments\Application;

use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\Ports\WebhookInbox;
use DateTimeImmutable;

/**
 * Webhooks can be lost, delayed or never sent (a provider outage, a misconfigured endpoint, a deploy at the wrong moment). Every few
 * minutes this looks at payments that should have finished by now and asks the provider directly.
 */
final class PaymentReconciler
{
    /** A payment that never got a provider reference (our call to the provider never completed) is abandoned after this long. */
    public const ABANDON_AFTER = '+45 minutes';

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentService $service,
        private readonly WebhookInbox $inbox,
        private readonly WebhookProcessor $processor,
    ) {}

    /** @return array{checked: int, changed: int, abandoned: int, errors: int} */
    public function run(?DateTimeImmutable $now = null, int $olderThanMinutes = 5, int $limit = 100): array
    {
        $now ??= new DateTimeImmutable;
        $report = ['checked' => 0, 'changed' => 0, 'abandoned' => 0, 'errors' => 0];

        foreach ($this->payments->openOlderThan($now->modify("-{$olderThanMinutes} minutes"), $limit) as $payment) {
            $report['checked']++;

            if ($payment['provider_reference'] === null) {
                if ($payment['created_at'] <= $now->modify('-'.ltrim(self::ABANDON_AFTER, '+'))->format('Y-m-d H:i:s')) {
                    $this->service->cancel($payment['id'], 'The provider never confirmed this payment.');
                    $report['abandoned']++;
                }

                continue;
            }

            try {
                $before = $payment['status'];
                $after = $this->service->sync($payment['id'])['status'];
                $after !== $before && $report['changed']++;
            } catch (PaymentError) {
                $report['errors']++; // already in the ledger; the next sweep tries again
            }
        }

        return $report;
    }

    /** Notifications whose retry time has come and that the queue did not pick up (worker was down, Redis was flushed...). @return int how many were processed */
    public function sweepWebhooks(?DateTimeImmutable $now = null, int $limit = 100): int
    {
        $n = 0;
        foreach ($this->inbox->due($now ?? new DateTimeImmutable, $limit) as $id) {
            $this->processor->process($id) === 'skipped' || $n++;
        }

        return $n;
    }
}
