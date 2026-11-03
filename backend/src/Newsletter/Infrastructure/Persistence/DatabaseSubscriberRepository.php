<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure\Persistence;

use Cronos\Newsletter\Domain\Ports\SubscriberRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseSubscriberRepository implements SubscriberRepository
{
    public function findByEmail(string $email): ?array
    {
        $r = DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', [strtolower($email)])->first();

        return $r === null ? null : (array) $r;
    }

    public function find(string $id): ?array
    {
        $r = DB::table('newsletter_subscribers')->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function create(array $row): string
    {
        $id = (string) Str::ulid();
        // Two sign-ups racing for the same address: the unique index lets one win; the other simply finds the row next time.
        DB::table('newsletter_subscribers')->insertOrIgnore($row + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return (string) DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', [strtolower((string) $row['email'])])->value('id');
    }

    public function update(string $id, array $changes): void
    {
        DB::table('newsletter_subscribers')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function delete(string $id): void
    {
        DB::table('newsletter_subscribers')->where('id', $id)->delete();
    }

    public function search(?string $status, ?string $term, int $limit, int $offset): array
    {
        $base = DB::table('newsletter_subscribers');
        if ($term !== null && trim($term) !== '') {
            $base->where('email', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%');
        }
        $counts = (clone $base)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
        $q = clone $base;
        $status !== null && $q->where('status', $status);
        $total = (clone $q)->count();

        return [
            'total' => $total,
            'counts' => ['pending' => (int) ($counts['pending'] ?? 0), 'confirmed' => (int) ($counts['confirmed'] ?? 0), 'unsubscribed' => (int) ($counts['unsubscribed'] ?? 0)],
            'rows' => $q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->offset($offset)->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }
}
