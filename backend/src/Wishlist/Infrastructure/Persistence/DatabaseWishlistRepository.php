<?php

declare(strict_types=1);

namespace Cronos\Wishlist\Infrastructure\Persistence;

use Cronos\Wishlist\Domain\Ports\WishlistRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseWishlistRepository implements WishlistRepository
{
    public function defaultListId(string $userId): string
    {
        $find = fn () => DB::table('wishlists')->where(['user_id' => $userId, 'is_default' => true])->whereNull('deleted_at')->value('id');
        if (($id = $find()) !== null) {
            return $id;
        }
        // Two tabs opening the wishlist for the first time race here: the partial unique index lets exactly one win.
        DB::table('wishlists')->insertOrIgnore(['id' => (string) Str::ulid(), 'user_id' => $userId, 'name' => 'Favoritos', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $find();
    }

    public function productIds(string $listId, int $limit, int $offset): array
    {
        return DB::table('wishlist_items')->where('wishlist_id', $listId)->orderByDesc('added_at')->orderByDesc('id')->limit($limit)->offset($offset)->pluck('product_id')->all();
    }

    public function count(string $listId): int
    {
        return DB::table('wishlist_items')->where('wishlist_id', $listId)->count();
    }

    public function has(string $listId, string $productId): bool
    {
        return DB::table('wishlist_items')->where(['wishlist_id' => $listId, 'product_id' => $productId])->exists();
    }

    public function add(string $listId, string $productId): void
    {
        DB::table('wishlist_items')->insertOrIgnore(['wishlist_id' => $listId, 'product_id' => $productId, 'added_at' => now()]);
    }

    public function remove(string $listId, string $productId): void
    {
        DB::table('wishlist_items')->where(['wishlist_id' => $listId, 'product_id' => $productId])->delete();
    }

    public function containing(string $listId, array $productIds): array
    {
        return $productIds === [] ? [] : DB::table('wishlist_items')->where('wishlist_id', $listId)->whereIn('product_id', $productIds)->pluck('product_id')->all();
    }
}
