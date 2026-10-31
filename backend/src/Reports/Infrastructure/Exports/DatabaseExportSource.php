<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Exports;

use Carbon\CarbonImmutable;
use Cronos\Reports\Domain\Ports\ExportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseExportSource implements ExportSource
{
    private const HEADERS = [
        'orders' => ['Pedido', 'Fecha (hora local)', 'Fecha (UTC)', 'Tienda', 'Moneda', 'Estado', 'Pago', 'Envío', 'Correo', 'Subtotal', 'Descuentos', 'Envío (monto)', 'Impuestos', 'Total', 'Reembolsado', 'Cupón', 'País (IP)'],
        'order_items' => ['Pedido', 'Fecha (hora local)', 'Tienda', 'Moneda', 'SKU', 'Producto', 'Unidad', 'Cantidad', 'Precio unitario', 'Descuento', 'Impuesto', 'Total'],
        'payments' => ['Pago', 'Fecha (hora local)', 'Fecha (UTC)', 'Proveedor', 'Modo', 'Estado', 'Monto', 'Moneda', 'Cobrado', 'Reembolsado', 'Referencia', 'Correo', 'Anomalía', 'País (IP)'],
        'stock' => ['SKU', 'Producto', 'Almacén', 'Tienda', 'Existencia', 'Apartado', 'Disponible'],
        'custom_orders' => ['Solicitud', 'Fecha (hora local)', 'Tienda', 'Estado', 'Título', 'Correo', 'Presupuesto', 'Fecha deseada'],
    ];

    public function headers(string $type): array
    {
        return self::HEADERS[$type];
    }

    public function rows(string $type, array $filters, string $timezone): \Generator
    {
        $local = static fn (?string $at): ?string => $at === null ? null : CarbonImmutable::parse($at)->setTimezone($timezone)->format('Y-m-d H:i:s');
        $utc = static fn (?string $at): ?string => $at === null ? null : CarbonImmutable::parse($at)->utc()->format('Y-m-d H:i:s');
        $num = static fn (mixed $v): float => (float) $v;

        $query = match ($type) {
            'orders' => $this->range(DB::table('orders as o')->whereNull('o.deleted_at'), 'o.created_at', $filters, $timezone)->when($filters['market'] ?? null, fn ($q, $m) => $q->where('o.market_code', $m))->when($filters['status'] ?? null, fn ($q, $s) => $q->where('o.status', $s))->select('o.*'),
            'order_items' => $this->range(DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->whereNull('o.deleted_at'), 'o.created_at', $filters, $timezone)->when($filters['market'] ?? null, fn ($q, $m) => $q->where('o.market_code', $m))->when($filters['status'] ?? null, fn ($q, $s) => $q->where('o.status', $s))
                ->select('i.id as item_id', 'i.product_name', 'i.sku', 'i.unit', 'i.quantity', 'i.unit_price', 'i.discount_amount', 'i.tax_amount', 'i.total as item_total', 'o.order_number', 'o.created_at', 'o.market_code', 'o.currency'),
            'payments' => $this->range(DB::table('payments as p'), 'p.created_at', $filters, $timezone)->when($filters['status'] ?? null, fn ($q, $s) => $q->where('p.status', $s))
                ->when($filters['market'] ?? null, fn ($q, $m) => $q->whereRaw('p.currency = (SELECT c.code FROM markets mk JOIN currencies c ON c.id = mk.currency_id WHERE mk.code = ?)', [$m]))->select('p.*'),
            'stock' => DB::table('stock_levels as s')->join('products as pr', 'pr.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')->leftJoin('markets as mk', 'mk.id', '=', 'w.market_id')
                ->whereNull('pr.deleted_at')->when($filters['market'] ?? null, fn ($q, $m) => $q->where('mk.code', $m))
                ->select('pr.sku', 's.product_id', 'w.name as warehouse', 'mk.code as market', 's.on_hand', 's.reserved', 's.available', DB::raw("(SELECT name FROM product_translations t WHERE t.product_id = COALESCE(pr.parent_id, pr.id) AND t.deleted_at IS NULL ORDER BY (t.locale = 'es-MX') DESC LIMIT 1) AS product_name")),
            'custom_orders' => $this->range(DB::table('custom_order_requests as r')->whereNull('r.deleted_at'), 'r.created_at', $filters, $timezone)->when($filters['market'] ?? null, fn ($q, $m) => $q->where('r.market_code', $m))->when($filters['status'] ?? null, fn ($q, $s) => $q->where('r.status', $s))->select('r.*'),
        };
        // Keyset walk by primary key in chunks: a deep row costs the same as the first one, and memory stays flat.
        // (stock levels have a composite key, so they are streamed in key order with a cursor instead).
        [$column, $alias] = ['orders' => ['o.id', 'id'], 'order_items' => ['i.id', 'item_id'], 'payments' => ['p.id', 'id'], 'custom_orders' => ['r.id', 'id']][$type] ?? [null, null];
        $stream = $type === 'stock' ? $query->orderBy('s.product_id')->orderBy('s.warehouse_id')->cursor() : $query->lazyById(1000, $column, $alias);
        foreach ($stream as $r) {
            yield match ($type) {
                'orders' => [$r->order_number, $local($r->created_at), $utc($r->created_at), $r->market_code, $r->currency, $r->status, $r->payment_status, $r->fulfillment_status, $r->email, $num($r->subtotal), $num($r->discount_total),
                    $num($r->shipping_total), $num($r->tax_total), $num($r->grand_total), $num($r->refunded_total), $r->coupon_code, $r->country_code],
                'order_items' => [$r->order_number, $local($r->created_at), $r->market_code, $r->currency, $r->sku, $r->product_name, $r->unit, $num($r->quantity), $num($r->unit_price), $num($r->discount_amount), $num($r->tax_amount), $num($r->item_total)],
                'payments' => [$r->id, $local($r->created_at), $utc($r->created_at), $r->provider, $r->mode, $r->status, $num($r->amount), $r->currency, $num($r->captured_amount), $num($r->refunded_amount), $r->provider_reference, $r->customer_email, $r->anomaly, $r->country_code],
                'stock' => [$r->sku, $r->product_name, $r->warehouse, $r->market, $num($r->on_hand), $num($r->reserved), $num($r->available)],
                'custom_orders' => [$r->number, $local($r->created_at), $r->market_code, $r->status, $r->title, $r->email, $r->budget === null ? null : $num($r->budget), $r->desired_date],
            };
        }
    }

    /** @param array<string, mixed> $f */
    private function range(Builder $q, string $column, array $f, string $timezone): Builder
    {
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;
        $from === null || $q->where($column, '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        $to === null || $q->where($column, '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc());

        return $q;
    }
}
