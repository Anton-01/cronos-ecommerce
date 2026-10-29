<?php

declare(strict_types=1);

namespace Cronos\Checkout\Application\Listeners;

use Cronos\Checkout\Domain\Ports\CheckoutRepository;
use Cronos\Payments\Domain\Events\PaymentNeedsReview;

/** Puts what Payments flagged (amount mismatch, dispute, late money) on the checkout, where whoever handles the order will look. */
final class NoteReviewOnCheckout
{
    public function __construct(private readonly CheckoutRepository $checkouts) {}

    public function handle(PaymentNeedsReview $e): void
    {
        if ($e->payableType !== 'checkout' || $this->checkouts->find($e->payableId) === null) {
            return;
        }
        $this->checkouts->addNote($e->payableId, 'payment_needs_review', "Payment {$e->paymentId}: {$e->reason}");
    }
}
