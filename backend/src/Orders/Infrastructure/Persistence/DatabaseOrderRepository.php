<?php

declare(strict_types=1);

namespace Cronos\Orders\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderRepository implements OrderRepository
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function find(string $id): ?array
    {
        return $this->order(DB::table('orders')->where('id', $id)->whereNull('deleted_at')->first());
    }

    public function lock(string $id): ?array
    {
        return $this->order(DB::table('orders')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first());
    }

    public function findByNumber(string $number): ?array
    {
        return $this->order(DB::table('orders')->where('order_number', strtoupper(trim($number)))->whereNull('deleted_at')->first());
    }

    public function findByCheckout(string $checkoutId): ?array
    {
        return $this->order(DB::table('orders')->where('checkout_id', $checkoutId)->first());
    }

    public function nextNumber(): string
    {
        return 'CR-'.str_pad((string) DB::selectOne("SELECT nextval('order_number_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    public function insert(array $order, array $items, array $addresses, array $discounts): void
    {
        DB::table('orders')->insert($order + ['created_at' => now(), 'updated_at' => now()]);
        foreach ($items as $item) {
            DB::table('order_items')->insert(['order_id' => $order['id'], 'options' => json_encode($item['options'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()] + array_diff_key($item, ['options' => 1]));
        }
        foreach ($addresses as $type => $data) {
            DB::table('order_addresses')->insert(['order_id' => $order['id'], 'type' => $type, 'data' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))]);
        }
        foreach ($discounts as $d) {
            DB::table('order_discounts')->insert(['order_id' => $order['id']] + $d);
        }
    }

    public function update(string $id, array $changes): void
    {
        DB::table('orders')->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    public function addHistory(string $orderId, array $entry): void
    {
        DB::table('order_histories')->insert($entry + ['order_id' => $orderId, 'note' => null, 'notify_customer' => false]);
    }

    public function items(string $orderId): array
    {
        return DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get()->map(static fn ($r) => [
            'id' => (int) $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku, 'unit' => $r->unit,
            'quantity' => rtrim(rtrim((string) $r->quantity, '0'), '.') ?: '0', 'unit_price' => (string) $r->unit_price, 'discount_amount' => (string) $r->discount_amount,
            'tax_rate' => (string) $r->tax_rate, 'tax_amount' => (string) $r->tax_amount, 'subtotal' => (string) $r->subtotal, 'total' => (string) $r->total,
            'options' => json_decode($r->options, true) ?? [],
        ])->all();
    }

    public function addresses(string $orderId): array
    {
        $out = [];
        foreach (DB::table('order_addresses')->where('order_id', $orderId)->get() as $r) {
            $out[$r->type] = json_decode(Crypt::decryptString($r->data), true);
        }

        return $out;
    }

    public function discounts(string $orderId): array
    {
        return DB::table('order_discounts')->where('order_id', $orderId)->orderBy('id')->get()->map(static fn ($r) => ['source' => $r->source, 'reference_id' => $r->reference_id, 'name' => $r->name, 'amount' => (string) $r->amount])->all();
    }

    public function histories(string $orderId): array
    {
        return DB::table('order_histories')->where('order_id', $orderId)->orderBy('id')->get()->map(static fn ($r) => [
            'id' => (int) $r->id, 'status' => $r->status, 'payment_status' => $r->payment_status, 'note' => $r->note, 'notify_customer' => (bool) $r->notify_customer,
            'created_by' => $r->created_by, 'ip_address' => $r->ip_address, 'country' => $r->country_code, 'timezone' => $r->timezone,
            'created_at' => CarbonImmutable::parse($r->created_at)->utc()->format('Y-m-d H:i:s.u'),
        ])->all();
    }

    public function forUser(string $userId, int $limit, int $offset): array
    {
        return DB::table('orders')->where('user_id', $userId)->whereNull('deleted_at')->orderByDesc('created_at')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->order($r))->all();
    }

    public function countForUser(string $userId): int
    {
        return DB::table('orders')->where('user_id', $userId)->whereNull('deleted_at')->count();
    }

    public function search(?string $status, ?string $paymentStatus, ?string $term, int $limit, int $offset): array
    {
        $q = DB::table('orders')->whereNull('deleted_at')->when($status, fn ($q) => $q->where('status', $status))->when($paymentStatus, fn ($q) => $q->where('payment_status', $paymentStatus));
        if ($term !== null && trim($term) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($term)).'%';
            $q->where(fn ($w) => $w->where('order_number', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('id', trim($term)));
        }

        return ['total' => (clone $q)->count(), 'rows' => $q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->offset($offset)->get()->map(fn ($r) => $this->order($r))->all()];
    }

    /** @return array<string, mixed>|null */
    private function order(?object $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $a = (array) $r;
        foreach (['subtotal', 'discount_total', 'shipping_total', 'tax_total', 'grand_total', 'refunded_total'] as $k) {
            $a[$k] = (string) $a[$k];
        }
        $a['tax_included'] = (bool) $a['tax_included'];
        $a['country'] = $a['country_code'];

        return $a;
    }
}
