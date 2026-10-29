<?php

declare(strict_types=1);

namespace Cronos\Notifications\Infrastructure\Persistence;

use Cronos\Notifications\Domain\Ports\NotificationLog;
use Illuminate\Support\Facades\DB;

final class DatabaseNotificationLog implements NotificationLog
{
    public function record(array $row): ?string
    {
        $row['data'] = json_encode($row['data'], JSON_THROW_ON_ERROR);
        $inserted = DB::table('notification_log')->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);

        return $inserted === 1 ? $row['id'] : null;
    }

    public function find(string $id): ?array
    {
        $r = DB::table('notification_log')->where('id', $id)->first();

        return $r === null ? null : $this->row($r);
    }

    public function update(string $id, array $changes): void
    {
        DB::table('notification_log')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function stale(\DateTimeImmutable $before, int $limit): array
    {
        return DB::table('notification_log')->whereIn('status', ['queued'])->where('updated_at', '<', $before->format('Y-m-d H:i:sP'))->orderBy('created_at')->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function search(?string $status, ?string $term, int $limit, int $offset): array
    {
        $q = DB::table('notification_log')->when($status, fn ($q) => $q->where('status', $status));
        if ($term !== null && trim($term) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($term)).'%';
            $q->where(fn ($w) => $w->where('email', 'ilike', $like)->orWhere('template', 'ilike', $like)->orWhere('related_id', trim($term)));
        }

        return ['total' => (clone $q)->count(), 'rows' => $q->orderByDesc('created_at')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->row($r))->all()];
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        $a = (array) $r;
        $a['data'] = json_decode((string) $a['data'], true) ?? [];

        return $a;
    }
}
