<?php

declare(strict_types=1);

namespace Cronos\Shipping\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Shipping\Domain\Ports\ShipmentRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseShipmentRepository implements ShipmentRepository
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function find(string $id): ?array
    {
        return $this->hydrate(DB::table('shipments')->where('id', $id)->first());
    }

    public function lock(string $id): ?array
    {
        return $this->hydrate(DB::table('shipments')->where('id', $id)->lockForUpdate()->first(), false);
    }

    public function findByTracking(string $carrier, string $trackingNumber): ?array
    {
        return $this->hydrate(DB::table('shipments')->where(['carrier' => $carrier, 'tracking_number' => $trackingNumber])->first(), false);
    }

    public function insert(array $shipment, array $items, array $packages): void
    {
        DB::table('shipments')->insert($shipment + ['created_at' => now(), 'updated_at' => now()]);
        foreach ($items as $i) {
            DB::table('shipment_items')->insert(['shipment_id' => $shipment['id']] + $i);
        }
        foreach ($packages as $p) {
            DB::table('shipment_packages')->insert(['shipment_id' => $shipment['id']] + $p);
        }
    }

    public function update(string $id, array $changes): void
    {
        DB::table('shipments')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function forOrder(string $orderId): array
    {
        return DB::table('shipments')->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get()->map(fn ($r) => $this->hydrate($r))->all();
    }

    public function shippedQuantities(string $orderId): array
    {
        $rows = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->where('s.order_id', $orderId)->whereNotIn('s.status', ['cancelled', 'returned'])
            ->groupBy('si.order_item_id')->selectRaw('si.order_item_id, SUM(si.quantity) AS qty')->get();

        return $rows->mapWithKeys(static fn ($r) => [(int) $r->order_item_id => (string) $r->qty])->all();
    }

    public function addEvent(string $shipmentId, array $event): bool
    {
        try {
            DB::transaction(fn () => DB::table('shipment_tracking_events')->insert(['shipment_id' => $shipmentId, 'raw' => $event['raw'] === null ? null : json_encode($event['raw'], JSON_THROW_ON_ERROR)] + $event));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function allDelivered(string $orderId): bool
    {
        $live = DB::table('shipments')->where('order_id', $orderId)->whereNotIn('status', ['cancelled', 'returned']);

        return (clone $live)->exists() && ! (clone $live)->where('status', '!=', 'delivered')->exists();
    }

    /** @return array<string, mixed>|null */
    private function hydrate(?object $r, bool $withDetail = true): ?array
    {
        if ($r === null) {
            return null;
        }
        $a = (array) $r;
        $a['shipping_cost'] = $a['shipping_cost'] === null ? null : (string) $a['shipping_cost'];
        if ($withDetail) {
            $a['items'] = DB::table('shipment_items')->where('shipment_id', $r->id)->orderBy('id')->get()->map(static fn ($i) => ['order_item_id' => (int) $i->order_item_id, 'quantity' => rtrim(rtrim((string) $i->quantity, '0'), '.') ?: '0'])->all();
            $a['events'] = DB::table('shipment_tracking_events')->where('shipment_id', $r->id)->orderBy('occurred_at')->orderBy('id')->get()->map(static fn ($e) => [
                'status' => $e->status, 'description' => $e->description, 'location' => $e->location, 'source' => $e->source,
                'occurred_at' => CarbonImmutable::parse($e->occurred_at)->utc()->format('Y-m-d H:i:s'),
            ])->all();
        }

        return $a;
    }
}
