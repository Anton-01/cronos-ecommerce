<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\Ports\StaffDirectory;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess;

/** Who is told about each process. Only people of the system can be added; adding and removing leave a trace. */
final class AlertSubscriptions
{
    public function __construct(private readonly AlertStore $store, private readonly StaffDirectory $staff) {}

    public function add(string $process, string $userId, ?string $by, bool $inbox = true, bool $email = true): void
    {
        AlertProcess::assertKnown($process);
        ($inbox || $email) || throw new InvalidMovement('Pick at least one way to be told (inbox or e-mail).', 'NO_CHANNEL');
        $this->staff->isStaff($userId) || throw new InvalidMovement('Only active people of the system can receive alerts.', 'NOT_A_SYSTEM_USER');
        $this->store->subscribe($process, $userId, $inbox, $email, $by);
    }

    public function remove(string $process, string $userId, ?string $by): void
    {
        AlertProcess::assertKnown($process);
        $this->store->unsubscribe($process, $userId, $by);
    }

    /** @return array<string, array{label: string, description: string, severity: string, subscribers: list<array{user_id: string, inbox: bool, email: bool}>}> */
    public function overview(): array
    {
        $out = [];
        foreach (AlertProcess::ALL as $code => $meta) {
            $out[$code] = $meta + ['subscribers' => $this->store->subscribers($code)];
        }

        return $out;
    }

    /** @return list<array{id: string, email: string, roles: list<string>}> the people that can be picked */
    public function candidates(): array
    {
        return $this->staff->all();
    }

    /** @return list<array<string, mixed>> */
    public function log(?string $process = null, int $limit = 100): array
    {
        return $this->store->subscriptionLog($process, $limit);
    }
}
