<?php

declare(strict_types=1);

namespace Cronos\Orders\Application\Listeners;

use Cronos\Orders\Domain\Events\OrderPlaced;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Payments\Domain\Events\PaymentCaptured;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Money arrived for an order that was waiting for it (custom orders). Anything odd goes to review instead of being marked paid:
 * a wrong amount, an order that was cancelled meanwhile, a payment anomaly. Idempotent: the outbox may deliver the capture twice.
 */
final class MarkOrderPaidOnCapture
{
    public function __construct(private readonly OrderRepository $orders, private readonly DomainEventDispatcher $events) {}

    public function handle(PaymentCaptured $e): void
    {
        if ($e->payableType !== 'order') {
            return;
        }

        $placed = $this->orders->atomically(function () use ($e): ?array {
            $order = $this->orders->lock($e->payableId);
            if ($order === null) {
                Log::critical('Payment captured for an unknown order', ['payment' => $e->paymentId, 'order' => $e->payableId]);

                return null;
            }
            if ($order['payment_id'] === $e->paymentId) {
                return null; // same delivery twice
            }
            $problem = match (true) {
                $order['payment_status'] !== 'unpaid' => 'The order was already paid or settled',
                $order['status'] !== 'pending' => "The order was {$order['status']} when the money arrived",
                $e->anomaly !== null => "Payment anomaly [{$e->anomaly}]",
                $e->amount !== $order['grand_total'] || $e->currency !== $order['currency'] => "Captured {$e->amount} {$e->currency}, expected {$order['grand_total']} {$order['currency']}",
                default => null,
            };
            if ($problem !== null) {
                $this->orders->addHistory($order['id'], ['status' => $order['status'], 'payment_status' => $order['payment_status'], 'note' => "NEEDS REVIEW — payment {$e->paymentId}: {$problem}"]);
                Log::critical('Order payment needs review', ['order' => $order['id'], 'payment' => $e->paymentId, 'problem' => $problem]);

                return null;
            }

            $this->orders->update($order['id'], ['status' => 'processing', 'payment_status' => 'paid', 'payment_id' => $e->paymentId, 'placed_at' => now()]);
            $this->orders->addHistory($order['id'], ['status' => 'processing', 'payment_status' => 'paid', 'note' => "Paid with payment {$e->paymentId}", 'notify_customer' => true]);

            return $this->orders->find($order['id']);
        });

        $placed === null || $this->events->dispatch(new OrderPlaced($placed['id'], $placed['order_number'], $placed['user_id'], $placed['email'], $placed['grand_total'], $placed['currency']));
    }
}
