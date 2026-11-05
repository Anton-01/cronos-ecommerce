<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Events\WarehouseAlertRaised;
use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\Ports\StaffDirectory;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Str;

/** Stores an alert in the inbox of everyone subscribed to its process and announces it so the e-mail goes out. Same dedupe key = said once. */
final class AlertRaiser
{
    public function __construct(private readonly AlertStore $store, private readonly StaffDirectory $staff, private readonly DomainEventDispatcher $events) {}

    /**
     * @param  list<string>  $alsoTell  extra people who must hear it whatever they subscribed to (e.g. the one who asked for an adjustment)
     * @param  array<string, mixed>  $data
     */
    public function raise(string $process, string $title, string $detail, string $dedupeKey, ?string $productId = null, ?int $warehouseId = null, ?string $locationId = null, array $data = [], array $alsoTell = [], ?string $severity = null): ?string
    {
        AlertProcess::assertKnown($process);
        $wants = [];
        foreach ($this->store->subscribers($process) as $s) {
            $wants[$s['user_id']] = ['inbox' => $s['inbox'], 'email' => $s['email']];
        }
        foreach ($alsoTell as $id) {
            $wants[$id] ??= ['inbox' => true, 'email' => true];
        }
        $people = $wants === [] ? [] : array_column($this->staff->staff(array_keys($wants)), 'email', 'id');

        $recipients = [];
        $mail = [];
        foreach ($wants as $userId => $how) {
            if (! isset($people[$userId])) {
                continue; // no longer active staff
            }
            $recipients[] = ['user_id' => $userId, 'by_email' => $how['email']];
            $how['email'] && $mail[] = ['user_id' => $userId, 'email' => $people[$userId]];
        }

        $id = strtolower((string) Str::ulid());
        $severity ??= AlertProcess::ALL[$process]['severity'];
        $created = $this->store->createAlert([
            'id' => $id, 'process' => $process, 'severity' => $severity, 'title' => mb_substr($title, 0, 160), 'detail' => $detail, 'data' => $data,
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'dedupe_key' => mb_substr($dedupeKey, 0, 160),
        ], $recipients);
        if (! $created) {
            return null;
        }
        $mail === [] || $this->events->dispatch(new WarehouseAlertRaised($id, $process, $severity, $title, $detail, $mail));

        return $id;
    }
}
