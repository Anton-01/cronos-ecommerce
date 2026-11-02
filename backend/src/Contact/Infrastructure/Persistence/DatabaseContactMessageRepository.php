<?php

declare(strict_types=1);

namespace Cronos\Contact\Infrastructure\Persistence;

use Cronos\Contact\Domain\Ports\ContactMessageRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseContactMessageRepository implements ContactMessageRepository
{
    public function create(array $row): string
    {
        $id = (string) Str::ulid();
        $row['spam_signals'] = json_encode($row['spam_signals']);
        DB::table('contact_messages')->insert($row + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function countFromIp(string $ip, int $minutes): int
    {
        return DB::table('contact_messages')->where('ip', $ip)->where('created_at', '>=', now()->subMinutes($minutes))->count();
    }

    public function countFromEmail(string $email, int $minutes): int
    {
        return DB::table('contact_messages')->where('email', $email)->where('created_at', '>=', now()->subMinutes($minutes))->count();
    }

    public function sameMessageRecently(string $email, string $ip, string $message, int $minutes): bool
    {
        return DB::table('contact_messages')->where('message', $message)->where(fn ($q) => $q->where('email', $email)->orWhere('ip', $ip))->where('created_at', '>=', now()->subMinutes($minutes))->exists();
    }

    public function search(?string $status, ?string $term, int $limit, int $offset): array
    {
        $q = DB::table('contact_messages');
        $status !== null && $q->where('status', $status);
        if ($term !== null && trim($term) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('message', 'ilike', $like)->orWhere('order_number', 'ilike', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->shape($r))->all();

        return ['total' => $total, 'rows' => $rows];
    }

    public function find(string $id): ?array
    {
        $row = DB::table('contact_messages')->where('id', $id)->first();

        return $row === null ? null : $this->shape($row);
    }

    public function setStatus(string $id, string $status, ?string $userId): void
    {
        DB::table('contact_messages')->where('id', $id)->update(['status' => $status, 'handled_by' => $userId, 'handled_at' => in_array($status, ['replied', 'read'], true) ? now() : null, 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function shape(object $r): array
    {
        $a = (array) $r;
        $a['spam_signals'] = json_decode((string) $r->spam_signals, true) ?: [];

        return $a;
    }
}
