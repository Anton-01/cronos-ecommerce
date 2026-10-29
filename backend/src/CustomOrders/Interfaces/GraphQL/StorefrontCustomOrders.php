<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Interfaces\GraphQL;

use Cronos\CustomOrders\Application\CustomOrderService;
use Cronos\CustomOrders\Domain\Exceptions\CustomOrderError;
use Cronos\CustomOrders\Domain\Ports\CustomOrderRepository;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Orders\Interfaces\GraphQL\OrderPresenter;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class StorefrontCustomOrders
{
    use ResolvesClientContext;

    public function __construct(
        private readonly CustomOrderService $service,
        private readonly CustomOrderRepository $requests,
        private readonly CustomOrderPresenter $present,
        private readonly OrderPresenter $orderPresenter,
        private readonly OrderRepository $orders,
    ) {}

    /** @param array<string, mixed> $args */
    public function mine(mixed $root, array $args, GraphQLContext $context): array
    {
        $uid = $this->uid($context);
        $rows = $this->requests->forUser($uid, max(1, min((int) ($args['first'] ?? 10), 50)), max(0, (int) ($args['offset'] ?? 0)));

        return ['total' => $this->requests->countForUser($uid), 'rows' => array_map($this->present->customer(...), $rows)];
    }

    /** @param array<string, mixed> $args */
    public function one(mixed $root, array $args, GraphQLContext $context): ?array
    {
        $r = $this->requests->findByNumber($args['number']);

        return $r !== null && $r['user_id'] === $this->uid($context) ? $this->present->customer($r) : null;
    }

    /** @param array<string, mixed> $args */
    public function submit(mixed $root, array $args, GraphQLContext $context): array
    {
        $in = $args['input'];
        $r = $this->service->submit($this->uid($context), $context->user()->email, $in['market'], $in['title'], $in['description'], (array) ($in['specifications'] ?? []), $in['desiredDate'] ?? null, $in['budget'] ?? null);

        return $this->present->customer($r);
    }

    /** @param array<string, mixed> $args */
    public function message(mixed $root, array $args, GraphQLContext $context): array
    {
        $r = $this->requests->findByNumber($args['number']);
        ($r !== null && $r['user_id'] === $this->uid($context)) || throw new CustomOrderError('That request does not exist.', 'REQUEST_NOT_FOUND');
        $this->service->customerMessage($r['id'], $this->uid($context), $args['body']);

        return $this->present->customer($r);
    }

    /** @param array<string, mixed> $args */
    public function accept(mixed $root, array $args, GraphQLContext $context): array
    {
        $order = $this->service->accept($args['quoteId'], $this->uid($context), $this->address($args['shippingAddress']), isset($args['billingAddress']) ? $this->address($args['billingAddress']) : null, $this->clientContext($context));

        return $this->orderPresenter->customer($order);
    }

    /** @param array<string, mixed> $args */
    public function decline(mixed $root, array $args, GraphQLContext $context): bool
    {
        $this->service->rejectQuote($args['quoteId'], $this->uid($context), $args['reason']);

        return true;
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function address(array $a): array
    {
        return [
            'recipient_name' => $a['recipientName'], 'phone' => $a['phone'] ?? null, 'street' => $a['street'], 'exterior_no' => $a['exteriorNo'] ?? null,
            'interior_no' => $a['interiorNo'] ?? null, 'neighborhood' => $a['neighborhood'] ?? null, 'city' => $a['city'], 'state' => $a['state'],
            'country_code' => strtoupper($a['countryCode']), 'zip' => $a['zip'], 'references' => $a['references'] ?? null,
        ];
    }

    private function uid(GraphQLContext $context): string
    {
        return (string) $context->user()->getAuthIdentifier();
    }
}
