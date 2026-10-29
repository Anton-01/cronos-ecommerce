<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Inventory\Domain\Ports\ReservationStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseReservationStore implements ReservationStore
{
    public function active(string $holderType, string $holderId, string $productId, int $warehouseId): ?array
    {
        $r = DB::table('inventory_reservations')->where(['holder_type' => $holderType, 'holder_id' => $holderId, 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'status' => 'active'])->lockForUpdate()->first();

        return $r === null ? null : $this->row($r);
    }

    public function activeOf(string $holderType, string $holderId): array
    {
        return DB::table('inventory_reservations')->where(['holder_type' => $holderType, 'holder_id' => $holderId, 'status' => 'active'])->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function activeList(?string $productId = null, int $limit = 200): array
    {
        return DB::table('inventory_reservations')->where('status', 'active')->when($productId, fn ($q) => $q->where('product_id', $productId))->orderBy('expires_at')->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function insert(string $productId, int $warehouseId, string $quantity, string $holderType, string $holderId, \DateTimeImmutable $expiresAt): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('inventory_reservations')->insert([
            'id' => $id, 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'holder_type' => $holderType, 'holder_id' => $holderId,
            'expires_at' => CarbonImmutable::instance($expiresAt)->utc()->format('Y-m-d H:i:s.u').'+00',
        ]);

        return $id;
    }

    public function setQuantity(string $id, string $quantity, \DateTimeImmutable $expiresAt): void
    {
        DB::table('inventory_reservations')->where('id', $id)->where('status', 'active')->update(['quantity' => $quantity, 'expires_at' => CarbonImmutable::instance($expiresAt)->utc()->format('Y-m-d H:i:s.u').'+00']);
    }

    public function resolve(string $id, string $status): void
    {
        DB::table('inventory_reservations')->where('id', $id)->where('status', 'active')->update(['status' => $status, 'resolved_at' => now()]);
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        return DB::table('inventory_reservations')->where('status', 'active')->where('expires_at', '<=', CarbonImmutable::instance($now)->utc()->format('Y-m-d H:i:s.u').'+00')
            ->orderBy('expires_at')->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return [
            'id' => $r->id, 'product_id' => $r->product_id, 'warehouse_id' => (int) $r->warehouse_id, 'quantity' => (string) $r->quantity, 'holder_type' => $r->holder_type, 'holder_id' => $r->holder_id,
            'status' => $r->status, 'expires_at' => CarbonImmutable::parse($r->expires_at)->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
