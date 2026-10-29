<?php

declare(strict_types=1);

namespace Cronos\Checkout\Application\Listeners;

use Brick\Math\BigDecimal;
use Cronos\Checkout\Domain\Events\CheckoutPaid;
use Cronos\Checkout\Domain\Ports\CartRepository;
use Cronos\Checkout\Domain\Ports\CheckoutRepository;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\ReservationService;
use Cronos\Payments\Domain\Events\PaymentCaptured;
use Cronos\Promotions\Application\CouponRedeemer;
use Cronos\Shared\Domain\DomainError;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * When the money is confirmed, sells the stock (Kardex), burns the coupon use and closes the cart. All or nothing in one
 * transaction, and idempotent: the payment outbox delivers at least once, so the same capture may arrive twice.
 *
 * Anything unexpected (money for an expired checkout, a second payment, an amount mismatch, stock that vanished) never loses the
 * payment: the checkout goes to `needs_review` with a note and a critical log, for a person to decide (fulfil or refund).
 */
final class SettleCheckoutOnPayment
{
    public function __construct(
        private readonly CheckoutRepository $checkouts,
        private readonly CartRepository $carts,
        private readonly ReservationService $reservations,
        private readonly CouponRedeemer $coupons,
        private readonly DomainEventDispatcher $events,
    ) {}

    public function handle(PaymentCaptured $e): void
    {
        if ($e->payableType !== 'checkout') {
            return;
        }

        $paid = $this->checkouts->atomically(function () use ($e): ?array {
            $checkout = $this->checkouts->lock($e->payableId);
            if ($checkout === null) {
                Log::critical('Payment captured for an unknown checkout', ['payment' => $e->paymentId, 'checkout' => $e->payableId]);

                return null;
            }
            if ($checkout['status'] === 'paid' || $checkout['payment_id'] === $e->paymentId) {
                if ($checkout['payment_id'] !== $e->paymentId) {
                    $this->review($checkout, $e, 'duplicate_payment', "A second payment {$e->paymentId} was captured for an already paid checkout: refund it.", false);
                }

                return null; // same delivery twice: nothing to do
            }
            if ($checkout['status'] !== 'open') {
                $this->review($checkout, $e, 'captured_after_'.$checkout['status'], "Payment {$e->paymentId} was captured after the checkout was {$checkout['status']}.", true);

                return null;
            }
            if ($e->anomaly !== null) {
                $this->review($checkout, $e, 'payment_anomaly', "Payment {$e->paymentId} was captured with anomaly [{$e->anomaly}].", true);

                return null;
            }
            if ($e->amount !== $checkout['total'] || $e->currency !== $checkout['currency']) {
                $this->review($checkout, $e, 'amount_mismatch', "Captured {$e->amount} {$e->currency}, expected {$checkout['total']} {$checkout['currency']}.", true);

                return null;
            }

            try {
                $this->reservations->consume('order', $checkout['id'], $checkout['id'], new Actor($checkout['user_id']));
            } catch (DomainError $err) {
                $this->review($checkout, $e, 'stock_lost', 'The stock reservation was gone when the money arrived: '.$err->getMessage(), true);

                return null;
            }

            if ($checkout['coupon_id'] !== null) {
                try {
                    $this->coupons->redeem($checkout['coupon_id'], $checkout['user_id'], $checkout['id'], (string) BigDecimal::of($checkout['items_discount'])->plus($checkout['shipping_discount']), $checkout['currency']);
                } catch (DomainError $err) {
                    $this->checkouts->addNote($checkout['id'], 'coupon_exhausted', 'Paid, but the coupon ran out meanwhile: '.$err->getMessage());
                }
            }

            $this->checkouts->update($checkout['id'], ['status' => 'paid', 'payment_id' => $e->paymentId, 'paid_at' => now()->format('Y-m-d H:i:s.uP')]);
            $this->carts->update($checkout['cart_id'], ['status' => 'converted']);

            return $checkout;
        });

        $paid === null || $this->events->dispatch(new CheckoutPaid($paid['id'], $e->paymentId, $paid['user_id'], $paid['total'], $paid['currency']));
    }

    /** @param array<string, mixed> $checkout */
    private function review(array $checkout, PaymentCaptured $e, string $code, string $detail, bool $changeStatus): void
    {
        $this->checkouts->addNote($checkout['id'], $code, $detail);
        $changeStatus && $this->checkouts->update($checkout['id'], ['status' => 'needs_review', 'payment_id' => $checkout['payment_id'] ?? $e->paymentId]);
        Log::critical('Checkout needs review', ['checkout' => $checkout['id'], 'payment' => $e->paymentId, 'code' => $code, 'detail' => $detail]);
    }
}
