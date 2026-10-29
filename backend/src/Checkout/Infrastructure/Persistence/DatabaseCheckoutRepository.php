<?php

declare(strict_types=1);

namespace Cronos\Checkout\Infrastructure\Persistence;

use Cronos\Checkout\Domain\Ports\CheckoutRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class DatabaseCheckoutRepository implements CheckoutRepository
{
    private const JSON = ['lines', 'applied_discounts', 'notes'];

    private const ENCRYPTED = ['shipping_address', 'billing_address'];

    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function find(string $id): ?array
    {
        return $this->hydrate(DB::table('checkouts')->where('id', $id)->first());
    }

    public function lock(string $id): ?array
    {
        return $this->hydrate(DB::table('checkouts')->where('id', $id)->lockForUpdate()->first());
    }

    public function findByKey(string $key): ?array
    {
        return $this->hydrate(DB::table('checkouts')->where('idempotency_key', $key)->first());
    }

    public function insert(array $checkout): void
    {
        DB::table('checkouts')->insert($this->dehydrate($checkout) + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function update(string $id, array $changes): void
    {
        DB::table('checkouts')->where('id', $id)->update($this->dehydrate($changes) + ['updated_at' => now()]);
    }

    public function addNote(string $id, string $code, string $detail): void
    {
        $entry = json_encode(['at' => (new DateTimeImmutable)->format(DATE_ATOM), 'code' => $code, 'detail' => mb_substr($detail, 0, 500)], JSON_THROW_ON_ERROR);
        DB::statement("UPDATE checkouts SET notes = COALESCE(notes, '[]'::jsonb) || ?::jsonb, updated_at = now() WHERE id = ?", [$entry, $id]);
    }

    public function openForCart(string $cartId): array
    {
        return DB::table('checkouts')->where(['cart_id' => $cartId, 'status' => 'open'])->get()->map(fn ($r) => $this->hydrate($r))->all();
    }

    public function hasPaid(string $userId): bool
    {
        return DB::table('checkouts')->where('user_id', $userId)->where('status', 'paid')->exists();
    }

    public function dueForExpiry(DateTimeImmutable $now, int $limit): array
    {
        return DB::table('checkouts')->where('status', 'open')->where('expires_at', '<=', $now->format('Y-m-d H:i:sP'))->orderBy('expires_at')->limit($limit)->get()
            ->map(fn ($r) => $this->hydrate($r))->all();
    }

    public function forUser(string $userId, int $limit): array
    {
        return DB::table('checkouts')->where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get()->map(fn ($r) => $this->hydrate($r))->all();
    }

    public function search(?string $status, ?string $term, int $limit, int $offset): array
    {
        $q = DB::table('checkouts');
        $status === null || $q->where('status', $status);
        if ($term !== null && trim($term) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';
            $q->where(fn ($w) => $w->where('id', 'like', $like)->orWhere('email', 'ilike', $like));
        }

        return $q->orderByDesc('created_at')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->hydrate($r))->all();
    }

    /** @return array<string, mixed>|null */
    private function hydrate(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $a = (array) $row;
        foreach (self::JSON as $k) {
            $a[$k] = json_decode((string) $a[$k], true, flags: JSON_THROW_ON_ERROR);
        }
        foreach (self::ENCRYPTED as $k) {
            $a[$k] = json_decode(Crypt::decryptString($a[$k]), true, flags: JSON_THROW_ON_ERROR);
        }
        $a['tax_included'] = (bool) $a['tax_included'];

        return $a;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function dehydrate(array $row): array
    {
        foreach (self::JSON as $k) {
            array_key_exists($k, $row) && $row[$k] = json_encode($row[$k], JSON_THROW_ON_ERROR);
        }
        foreach (self::ENCRYPTED as $k) {
            array_key_exists($k, $row) && $row[$k] = Crypt::encryptString(json_encode($row[$k], JSON_THROW_ON_ERROR));
        }

        return $row;
    }
}
