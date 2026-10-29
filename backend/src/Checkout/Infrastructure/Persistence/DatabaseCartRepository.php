<?php

declare(strict_types=1);

namespace Cronos\Checkout\Infrastructure\Persistence;

use Cronos\Checkout\Domain\Ports\CartRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseCartRepository implements CartRepository
{
    public function find(string $id): ?array
    {
        $r = DB::table('carts')->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function findActiveByTokenHash(string $hash): ?array
    {
        $r = DB::table('carts')->where('token_hash', $hash)->where('status', 'active')->where('expires_at', '>', now())->first();

        return $r === null ? null : (array) $r;
    }

    public function findActiveForUser(string $userId): ?array
    {
        $r = DB::table('carts')->where('user_id', $userId)->where('status', 'active')->first();

        return $r === null ? null : (array) $r;
    }

    public function insert(array $cart): string
    {
        $id = (string) Str::ulid();
        DB::table('carts')->insert($cart + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function update(string $id, array $changes): void
    {
        DB::table('carts')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function items(string $cartId): array
    {
        return DB::table('cart_items')->where('cart_id', $cartId)->orderBy('id')->get()
            ->map(static fn ($r) => ['product_id' => $r->product_id, 'quantity' => rtrim(rtrim((string) $r->quantity, '0'), '.') ?: '0', 'by_roll' => (bool) $r->by_roll])->all();
    }

    public function setItem(string $cartId, string $productId, string $quantity, bool $byRoll): void
    {
        DB::table('cart_items')->upsert(
            [['cart_id' => $cartId, 'product_id' => $productId, 'quantity' => $quantity, 'by_roll' => $byRoll, 'created_at' => now(), 'updated_at' => now()]],
            ['cart_id', 'product_id'], ['quantity', 'by_roll', 'updated_at'],
        );
    }

    public function removeItem(string $cartId, string $productId): void
    {
        DB::table('cart_items')->where(['cart_id' => $cartId, 'product_id' => $productId])->delete();
    }

    public function clearItems(string $cartId): void
    {
        DB::table('cart_items')->where('cart_id', $cartId)->delete();
    }

    public function mergeInto(string $targetId, string $sourceId): void
    {
        $mine = DB::table('cart_items')->where('cart_id', $targetId)->get()->keyBy('product_id');
        foreach (DB::table('cart_items')->where('cart_id', $sourceId)->get() as $item) {
            $existing = $mine->get($item->product_id);
            if ($existing !== null && (float) $existing->quantity >= (float) $item->quantity) {
                continue; // keep the larger one: merging must not silently double what the shopper wanted
            }
            $this->setItem($targetId, $item->product_id, (string) $item->quantity, (bool) $item->by_roll);
        }
        DB::table('cart_items')->where('cart_id', $sourceId)->delete();
    }

    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }
}
