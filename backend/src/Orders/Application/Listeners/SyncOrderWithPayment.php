<?php

declare(strict_types=1);

namespace Cronos\Orders\Application\Listeners;

use Cronos\Orders\Application\OrderService;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Payments\Domain\Events\PaymentRefunded;

/** Refunds recorded by Payments (from the admin, a provider dashboard or a cancellation) update the order's money status. */
final class SyncOrderWithPayment
{
    public function __construct(private readonly OrderRepository $orders, private readonly OrderService $service) {}

    public function handle(PaymentRefunded $e): void
    {
        $order = match ($e->payableType) {
            'checkout' => $this->orders->findByCheckout($e->payableId),
            'order' => $this->orders->find($e->payableId),
            default => null,
        };
        $order === null || $this->service->applyRefund($order['id'], $e->refundedTotal, $e->full);
    }
}
