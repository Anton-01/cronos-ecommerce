<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Payments\Domain\Ports\WebhookInbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseWebhookInbox implements WebhookInbox
{
    public function store(array $event): array
    {
        $id = strtolower((string) Str::ulid());
        try {
            DB::transaction(fn () => DB::table('webhook_events')->insert(['id' => $id, 'headers' => json_encode($event['headers'] ?? [], JSON_THROW_ON_ERROR), 'received_at' => now()] + array_diff_key($event, ['headers' => 1])));

            return ['id' => $id, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            // The provider delivered the same event again: acknowledge it, process nothing new.
            return ['id' => (string) DB::table('webhook_events')->where(['provider' => $event['provider'], 'event_id' => $event['event_id']])->value('id'), 'created' => false];
        }
    }

    public function find(string $id): ?array
    {
        $r = DB::table('webhook_events')->where('id', $id)->first();

        return $r === null ? null : $this->row($r);
    }

    public function claim(string $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $r = DB::table('webhook_events')->where('id', $id)->lockForUpdate()->first();
            if ($r === null || ! in_array($r->status, ['received', 'failed'], true)) {
                return null;
            }
            // A "processing" claim older than five minutes belongs to a worker that died: it is claimable again (see due()).
            DB::table('webhook_events')->where('id', $id)->update(['status' => 'processing', 'attempts' => $r->attempts + 1, 'next_attempt_at' => now()->addMinutes(5)]);

            return $this->row(DB::table('webhook_events')->where('id', $id)->first());
        });
    }

    public function update(string $id, array $changes): void
    {
        if (array_key_exists('headers', $changes)) {
            $changes['headers'] = json_encode($changes['headers'], JSON_THROW_ON_ERROR);
        }
        DB::table('webhook_events')->where('id', $id)->update($changes);
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        $at = CarbonImmutable::instance($now)->utc();
        // `processing` with an expired lease = the worker died mid-way: put it back first.
        DB::table('webhook_events')->where('status', 'processing')->where('next_attempt_at', '<=', $at)->update(['status' => 'failed', 'last_error' => 'The worker stopped before finishing; retrying.']);

        return DB::table('webhook_events')->whereIn('status', ['received', 'failed'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $at))
            ->orderBy('received_at')->limit($limit)->pluck('id')->all();
    }

    public function search(?string $status, ?string $provider, int $limit, int $offset): array
    {
        $q = DB::table('webhook_events')->when($status, fn ($q) => $q->where('status', $status))->when($provider, fn ($q) => $q->where('provider', $provider));

        return ['total' => (clone $q)->count(), 'rows' => $q->orderByDesc('received_at')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->row($r))->all()];
    }

    public function failedIds(int $limit): array
    {
        return DB::table('webhook_events')->whereIn('status', ['failed', 'dead'])->orderBy('received_at')->limit($limit)->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return [
            'id' => $r->id, 'provider' => $r->provider, 'mode' => $r->mode, 'event_id' => $r->event_id, 'event_type' => $r->event_type, 'signature_valid' => (bool) $r->signature_valid,
            'raw_body' => $r->raw_body, 'headers' => json_decode($r->headers, true) ?? [], 'status' => $r->status, 'attempts' => (int) $r->attempts, 'last_error' => $r->last_error,
            'next_attempt_at' => $r->next_attempt_at === null ? null : CarbonImmutable::parse($r->next_attempt_at)->utc()->format('Y-m-d H:i:s'),
            'received_at' => CarbonImmutable::parse($r->received_at)->utc()->format('Y-m-d H:i:s'), 'processed_at' => $r->processed_at === null ? null : CarbonImmutable::parse($r->processed_at)->utc()->format('Y-m-d H:i:s'),
            'payment_id' => $r->payment_id, 'ip' => $r->ip_address,
        ];
    }
}
