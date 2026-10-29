<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Interfaces\GraphQL;

use Cronos\CustomOrders\Domain\Ports\CustomOrderRepository;
use Cronos\Orders\Domain\Ports\OrderRepository;

final class CustomOrderPresenter
{
    public function __construct(private readonly CustomOrderRepository $requests, private readonly OrderRepository $orders) {}

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public function customer(array $r): array
    {
        return $this->base($r) + [
            'messages' => array_map(static fn (array $m) => ['id' => $m['id'], 'author' => $m['author_type'], 'body' => $m['body'], 'at' => $m['created_at']], $this->requests->messages($r['id'], false)),
            'quotes' => array_map($this->quote(...), $this->requests->quotes($r['id'], false)),
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public function admin(array $r): array
    {
        return $this->base($r) + [
            'email' => $r['email'], 'userId' => $r['user_id'], 'assignedTo' => $r['assigned_to'], 'orderId' => $r['order_id'],
            'messages' => array_map(static fn (array $m) => ['id' => $m['id'], 'author' => $m['author_type'], 'authorId' => $m['author_id'], 'body' => $m['body'], 'internal' => $m['is_internal'], 'at' => $m['created_at']], $this->requests->messages($r['id'], true)),
            'quotes' => array_map($this->quote(...), $this->requests->quotes($r['id'], true)),
        ];
    }

    /** @param array<string, mixed> $q @return array<string, mixed> */
    public function quote(array $q): array
    {
        return [
            'id' => $q['id'], 'version' => (int) $q['version'], 'status' => $q['status'], 'currency' => $q['currency'], 'taxIncluded' => $q['tax_included'], 'subtotal' => $q['subtotal'],
            'tax' => $q['tax_total'], 'shipping' => $q['shipping_total'], 'total' => $q['grand_total'], 'validUntil' => $q['valid_until'], 'terms' => $q['terms'], 'sentAt' => $q['sent_at'],
            'lines' => array_map(static fn (array $l) => ['description' => $l['description'], 'unit' => $l['unit'], 'quantity' => $l['quantity'], 'unitPrice' => $l['unit_price'], 'taxRate' => $l['tax_rate'], 'tax' => $l['tax_amount'], 'total' => $l['total']], $q['lines']),
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function base(array $r): array
    {
        return [
            'id' => $r['id'], 'number' => $r['number'], 'status' => $r['status'], 'marketCode' => $r['market_code'], 'title' => $r['title'], 'description' => $r['description'],
            'specifications' => $r['specifications'], 'desiredDate' => $r['desired_date'], 'budget' => $r['budget'], 'rejectionReason' => $r['rejection_reason'], 'createdAt' => $r['created_at'],
            'orderNumber' => $r['order_id'] === null ? null : ($this->orders->find($r['order_id'])['order_number'] ?? null),
        ];
    }
}
