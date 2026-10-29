<?php

declare(strict_types=1);

namespace Cronos\Orders\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\OrderStockReturner;
use Cronos\Orders\Domain\Events\OrderStatusChanged;
use Cronos\Orders\Domain\Exceptions\OrderError;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Orders\Domain\Services\OrderStateMachine as M;
use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Shared\Domain\ClientContext;
use Cronos\Shared\Domain\DomainEventDispatcher;

/**
 * Everything that happens to an order after it exists. Each move locks the row, checks the state machine, and writes the history row in
 * the same transaction; the customer is told only when the move says so.
 */
final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderStockReturner $stock,
        private readonly PaymentService $payments,
        private readonly DomainEventDispatcher $events,
    ) {}

    /** @return array<string, mixed> the order */
    public function transition(string $orderId, string $to, ?string $note, bool $notify, ?string $userId, ?ClientContext $client = null): array
    {
        $to !== M::CANCELLED || throw new OrderError('Use the cancel action: it also puts the stock back and can refund.', 'USE_CANCEL');
        $from = $this->move($orderId, $to, $note, $notify, $userId, $client);
        $this->events->dispatch(new OrderStatusChanged($orderId, $this->orders->find($orderId)['order_number'], $from, $to, $notify));

        return $this->orders->find($orderId);
    }

    /**
     * Cancels an unshipped order: status, stock back on the shelf (Kardex RETURN) and, when asked, the money back. The refund goes last and
     * has its own idempotency key; if the provider is down the order is still cancelled and the history says the refund is pending.
     *
     * @return array{order: array<string, mixed>, refund_error: ?string}
     */
    public function cancel(string $orderId, string $reason, bool $refund, ?string $userId, ?ClientContext $client = null): array
    {
        $order = $this->orders->find($orderId) ?? throw new OrderError('That order does not exist.', 'ORDER_NOT_FOUND');

        $from = $this->orders->atomically(function () use ($order, $reason, $userId, $client): string {
            $from = $this->move($order['id'], M::CANCELLED, 'Cancelled: '.$reason, true, $userId, $client);
            $this->stock->returnOrder($order['checkout_id'] ?? $order['id'], new Actor($userId, $client), 'Order '.$order['order_number'].' cancelled');
            $this->orders->update($order['id'], ['cancelled_at' => now()]);

            return $from;
        });
        $this->events->dispatch(new OrderStatusChanged($orderId, $order['order_number'], $from, M::CANCELLED, true));

        $error = null;
        $fresh = $this->orders->find($orderId);
        $refundable = BigDecimal::of($fresh['grand_total'])->minus($fresh['refunded_total']);
        if ($refund && $fresh['payment_id'] !== null && $fresh['payment_status'] === 'paid' && $refundable->isPositive()) {
            try {
                $this->payments->refund($fresh['payment_id'], (string) $refundable->toScale(2), 'Order '.$order['order_number'].' cancelled', 'order-cancel:'.$orderId, $userId, $client);
            } catch (PaymentError $e) {
                $error = $e->getMessage();
                $this->orders->addHistory($orderId, ['status' => M::CANCELLED, 'payment_status' => $fresh['payment_status'], 'note' => 'Refund pending, retry from Payments: '.$error, 'created_by' => $userId]);
            }
        }

        return ['order' => $this->orders->find($orderId), 'refund_error' => $error];
    }

    /** Money moved at the provider: keep the order's payment_status and refunded total true to the payment. */
    public function applyRefund(string $orderId, string $refundedTotal, bool $full): void
    {
        $status = $this->orders->atomically(function () use ($orderId, $refundedTotal, $full): ?string {
            $order = $this->orders->lock($orderId);
            if ($order === null || BigDecimal::of($refundedTotal)->isLessThanOrEqualTo($order['refunded_total'])) {
                return null; // unknown order, or an older/duplicate notice
            }
            $changes = ['refunded_total' => $refundedTotal, 'payment_status' => $full ? 'refunded' : 'partially_refunded'];
            if ($full && M::can($order['status'], M::REFUNDED)) {
                $changes['status'] = M::REFUNDED;
            }
            $this->orders->update($orderId, $changes);
            $this->orders->addHistory($orderId, ['status' => $changes['status'] ?? $order['status'], 'payment_status' => $changes['payment_status'], 'note' => "Refunded {$refundedTotal} {$order['currency']} in total", 'notify_customer' => true]);

            return $changes['status'] ?? $order['status'];
        });
        $status === null || $this->events->dispatch(new OrderStatusChanged($orderId, $this->orders->find($orderId)['order_number'], $status, $status, true));
    }

    /** Disputes (chargebacks) change the money status only; a person decides what to do with the goods. */
    public function markDisputed(string $orderId): void
    {
        $this->orders->atomically(function () use ($orderId): void {
            $order = $this->orders->lock($orderId);
            if ($order === null || $order['payment_status'] === 'disputed') {
                return;
            }
            $this->orders->update($orderId, ['payment_status' => 'disputed']);
            $this->orders->addHistory($orderId, ['status' => $order['status'], 'payment_status' => 'disputed', 'note' => 'The payment was disputed by the cardholder']);
        });
    }

    /** @return string the previous status */
    private function move(string $orderId, string $to, ?string $note, bool $notify, ?string $userId, ?ClientContext $client): string
    {
        return $this->orders->atomically(function () use ($orderId, $to, $note, $notify, $userId, $client): string {
            $order = $this->orders->lock($orderId) ?? throw new OrderError('That order does not exist.', 'ORDER_NOT_FOUND');
            M::assert($order['status'], $to);
            $this->orders->update($orderId, ['status' => $to]);
            $this->orders->addHistory($orderId, [
                'status' => $to, 'payment_status' => $order['payment_status'], 'note' => $note, 'notify_customer' => $notify, 'created_by' => $userId,
                'ip_address' => $client?->ip, 'country_code' => $client?->country, 'timezone' => $client?->timezone,
            ]);

            return $order['status'];
        });
    }
}
