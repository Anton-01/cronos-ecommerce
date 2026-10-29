<?php

declare(strict_types=1);

namespace Cronos\Audit\Interfaces\GraphQL;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdminAuditsQuery
{
    private const MAX_PAGE = 100;

    /** @param array<string, mixed> $args @return array{entries: list<array<string, mixed>>, nextBefore: ?string} */
    public function __invoke(mixed $root, array $args): array
    {
        $limit = max(1, min((int) ($args['first'] ?? 50), self::MAX_PAGE));

        $rows = DB::table('audits as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->when($args['entity'] ?? null, fn ($q, $v) => $q->where('a.auditable_type', 'like', '%\\'.addcslashes($v, '%_\\')))
            ->when($args['entityId'] ?? null, fn ($q, $v) => $q->where('a.auditable_id', $v))
            ->when($args['userId'] ?? null, fn ($q, $v) => $q->where('a.user_id', $v))
            ->when($args['country'] ?? null, fn ($q, $v) => $q->where('a.country_code', strtoupper($v)))
            ->when($args['event'] ?? null, fn ($q, $v) => $q->where('a.event', $v))
            ->when($args['before'] ?? null, fn ($q, $v) => $q->where('a.id', '<', (int) $v))
            ->orderByDesc('a.id')->limit($limit + 1)
            ->get(['a.id', 'a.event', 'a.auditable_type', 'a.auditable_id', 'a.user_id', 'u.email as user_email', 'a.old_values', 'a.new_values', 'a.ip_address', 'a.country_code', 'a.timezone', 'a.created_at']);

        $page = $rows->take($limit);

        return [
            'entries' => $page->map(fn ($r) => [
                'id' => (string) $r->id, 'event' => $r->event, 'entity' => class_basename($r->auditable_type), 'entityId' => $r->auditable_id,
                'userId' => $r->user_id, 'userEmail' => $r->user_email,
                'oldValues' => $r->old_values === null ? null : json_decode($r->old_values, true), 'newValues' => $r->new_values === null ? null : json_decode($r->new_values, true),
                'ip' => $r->ip_address, 'country' => $r->country_code, 'timezone' => $r->timezone,
                'createdAt' => Carbon::parse($r->created_at), 'createdAtUtc' => Carbon::parse($r->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->values()->all(),
            'nextBefore' => $rows->count() > $limit ? (string) $page->last()->id : null,
        ];
    }
}
