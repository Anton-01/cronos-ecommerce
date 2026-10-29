<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Persistence;

use Cronos\Payments\Domain\Ports\Outbox;
use Cronos\Payments\Infrastructure\Jobs\RelayOutboxJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseOutbox implements Outbox
{
    public function add(string $type, array $payload): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('outbox_messages')->insert(['id' => $id, 'type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        // Deliver right after the surrounding transaction commits (never before: listeners must see the committed payment).
        DB::afterCommit(fn () => RelayOutboxJob::dispatch());

        return $id;
    }

    public function claimDue(int $limit): array
    {
        // SKIP LOCKED: several relays can run side by side without delivering the same message twice at the same moment.
        return DB::transaction(function () use ($limit): array {
            $rows = DB::select("SELECT id, type, payload, attempts FROM outbox_messages WHERE status = 'pending' AND available_at <= now() ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED", [$limit]);
            // Lease: until it is marked, nobody else sees it for a minute (a crashed relay's messages come back on their own).
            $rows === [] || DB::table('outbox_messages')->whereIn('id', array_column($rows, 'id'))->update(['available_at' => now()->addMinute()]);

            return array_map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'payload' => json_decode($r->payload, true), 'attempts' => (int) $r->attempts], $rows);
        });
    }

    public function markDispatched(string $id): void
    {
        DB::table('outbox_messages')->where('id', $id)->update(['status' => 'dispatched', 'dispatched_at' => now()]);
    }

    public function markFailed(string $id, string $error, \DateTimeImmutable $retryAt, bool $giveUp): void
    {
        DB::table('outbox_messages')->where('id', $id)->update(['status' => $giveUp ? 'failed' : 'pending', 'last_error' => $error, 'attempts' => DB::raw('attempts + 1'), 'available_at' => $retryAt]);
    }
}
