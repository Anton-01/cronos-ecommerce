<?php

declare(strict_types=1);

namespace Cronos\Orders\Interfaces\GraphQL;

use Cronos\Orders\Application\OrderService;
use Cronos\Orders\Domain\Exceptions\OrderError;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Cronos\Shipping\Application\ShipmentService;
use Cronos\Shipping\Domain\Ports\ShipmentRepository;
use DateTimeImmutable;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminOrders
{
    use ResolvesClientContext;

    private const MAX_PAGE = 100;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderService $service,
        private readonly ShipmentService $shipments,
        private readonly ShipmentRepository $shipmentRows,
        private readonly OrderPresenter $present,
    ) {}

    /** @param array<string, mixed> $args */
    public function orders(mixed $root, array $args): array
    {
        $page = $this->orders->search($args['status'] ?? null, $args['paymentStatus'] ?? null, $args['search'] ?? null, max(1, min((int) ($args['first'] ?? 25), self::MAX_PAGE)), max(0, (int) ($args['offset'] ?? 0)));

        return ['total' => $page['total'], 'rows' => array_map($this->present->admin(...), $page['rows'])];
    }

    /** @param array<string, mixed> $args */
    public function order(mixed $root, array $args): ?array
    {
        $o = $this->orders->find($args['id']);

        return $o === null ? null : $this->present->admin($o);
    }

    /** @param array<string, mixed> $args */
    public function transition(mixed $root, array $args, GraphQLContext $context): array
    {
        $o = $this->service->transition($args['id'], $args['to'], $args['note'] ?? null, (bool) ($args['notifyCustomer'] ?? false), $this->userId($context), $this->clientContext($context));

        return $this->present->admin($o);
    }

    /** @param array<string, mixed> $args */
    public function cancel(mixed $root, array $args, GraphQLContext $context): array
    {
        trim($args['reason']) !== '' || throw new OrderError('Say why the order is cancelled.', 'REASON_REQUIRED');
        $r = $this->service->cancel($args['id'], trim($args['reason']), (bool) ($args['refund'] ?? true), $this->userId($context), $this->clientContext($context));

        return ['order' => $this->present->admin($r['order']), 'refundError' => $r['refund_error']];
    }

    /** @param array<string, mixed> $args */
    public function createShipment(mixed $root, array $args, GraphQLContext $context): array
    {
        $in = $args['input'];
        $s = $this->shipments->create(
            $in['orderId'], $in['carrier'], $in['service'] ?? null, $in['trackingNumber'] ?? null, $in['trackingUrl'] ?? null,
            array_map(static fn (array $i) => ['order_item_id' => (int) $i['orderItemId'], 'quantity' => $i['quantity']], $in['items'] ?? []),
            array_map(static fn (array $p) => ['weight_kg' => $p['weightKg'] ?? null, 'length_cm' => $p['lengthCm'] ?? null, 'width_cm' => $p['widthCm'] ?? null, 'height_cm' => $p['heightCm'] ?? null], $in['packages'] ?? []),
            $in['cost'] ?? null, isset($in['estimatedDeliveryAt']) ? DateTimeImmutable::createFromInterface($in['estimatedDeliveryAt']) : null, $this->userId($context), $this->clientContext($context),
        );

        return $this->shipment($s);
    }

    /** @param array<string, mixed> $args */
    public function markDelivered(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->shipments->markDelivered($args['id'], $this->userId($context));

        return $this->shipment($this->shipmentRows->find($args['id']));
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function shipment(array $s): array
    {
        $order = $this->present->admin($this->orders->find($s['order_id']));

        return collect($order['shipments'])->firstWhere('id', $s['id']);
    }

    private function userId(GraphQLContext $context): ?string
    {
        $id = $context->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
