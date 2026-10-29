<?php

declare(strict_types=1);

namespace Cronos\Checkout\Application;

use Brick\Math\BigDecimal;
use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Checkout\Domain\Ports\CartRepository;
use Cronos\Checkout\Domain\Ports\CheckoutRepository;
use Cronos\Checkout\Domain\Ports\SellableProducts;
use Cronos\Fraud\Application\FraudService;
use Cronos\Fraud\Domain\ValueObjects\FraudContext;
use Cronos\Fraud\Domain\ValueObjects\FraudDecision;
use Cronos\Inventory\Application\ReservationService;
use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Application\ProviderCatalog;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Shared\Domain\ClientContext;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Freezes the basket into a checkout (prices, taxes, discounts, shipping, addresses), holds the stock for a while and hands the
 * money part to Payments. Starting twice with the same key returns the same checkout, so a double click or a retried request
 * never reserves stock or charges twice.
 */
final class CheckoutService
{
    private const ADDRESS_REQUIRED = ['recipient_name', 'street', 'city', 'state', 'country_code', 'zip'];

    public function __construct(
        private readonly CartRepository $carts,
        private readonly CheckoutRepository $checkouts,
        private readonly CartPricer $pricer,
        private readonly SellableProducts $products,
        private readonly ReservationService $reservations,
        private readonly FraudService $fraud,
        private readonly PaymentService $payments,
        private readonly PaymentRepository $paymentRows,
        private readonly ProviderRepository $providers,
    ) {}

    /**
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>|null  $billing  defaults to the shipping address
     * @return array<string, mixed> the checkout
     */
    public function start(string $cartId, ?string $userId, string $email, array $shipping, ?array $billing, string $idempotencyKey, ?ClientContext $client = null): array
    {
        $existing = $this->checkouts->findByKey($idempotencyKey);
        if ($existing !== null) {
            $existing['cart_id'] === $cartId || throw new CheckoutError('That idempotency key was already used for a different cart.', 'IDEMPOTENCY_MISMATCH');

            return $existing;
        }

        $cart = $this->carts->find($cartId);
        ($cart !== null && $cart['status'] === 'active') || throw new CheckoutError('That cart is no longer active.', 'CART_CLOSED');
        $email = strtolower(trim($email));
        filter_var($email, FILTER_VALIDATE_EMAIL) !== false || throw new CheckoutError('The email address is not valid.', 'INVALID_EMAIL');
        $billing ??= $shipping;
        $this->assertAddress($shipping);
        $this->assertAddress($billing);
        $country = $this->products->marketCountry($cart['market_code']);
        strtoupper((string) $shipping['country_code']) === $country || throw new CheckoutError("This store only ships to {$country} for the {$cart['market_code']} market.", 'SHIPPING_COUNTRY_MISMATCH');

        $items = $this->carts->items($cartId);
        $priced = $this->pricer->price($cart['market_code'], $cart['region'], $items, $cart['coupon_code'], $userId, $userId !== null && ! $this->checkouts->hasPaid($userId));

        $id = (string) Str::ulid();
        $decision = $this->fraud->assess(new FraudContext(
            $client?->ip, $email, $userId, new Money($priced['total'], $priced['currency'], $priced['decimals']),
            strtoupper((string) $billing['country_code']), strtoupper((string) $shipping['country_code']),
        ), $id);
        $decision->decision !== FraudDecision::BLOCK || throw new CheckoutError('We could not process this order. Please contact support.', 'FRAUD_BLOCKED');

        // A newer checkout of the same cart replaces the old one: give its stock back first so it does not compete with itself.
        foreach ($this->checkouts->openForCart($cart['id']) as $old) {
            $this->abandon($old['id'], 'cancelled', 'Replaced by a newer checkout of the same cart.');
        }

        // Stock first: if it is not there, nothing else is created. Rolls are held as the metres they contain.
        $ttl = (int) config('checkout.ttl_minutes', 30);
        $this->reservations->reserve('order', $id, $this->reservable($items), $ttl, $cart['market_code']);

        try {
            $this->checkouts->atomically(function () use ($id, $cart, $userId, $email, $shipping, $billing, $priced, $idempotencyKey, $decision, $ttl, $client): void {
                $this->checkouts->insert([
                    'id' => $id, 'cart_id' => $cart['id'], 'user_id' => $userId, 'idempotency_key' => $idempotencyKey, 'email' => $email,
                    'market_code' => $cart['market_code'], 'region' => $cart['region'], 'currency' => $priced['currency'], 'tax_included' => $priced['tax_included'],
                    'lines' => $priced['lines'], 'applied_discounts' => $priced['applied'],
                    'subtotal' => $priced['subtotal'], 'items_discount' => $priced['items_discount'], 'shipping' => $priced['shipping'],
                    'shipping_discount' => $priced['shipping_discount'], 'tax' => $priced['tax'], 'total' => $priced['total'],
                    'coupon_id' => $priced['coupon_id'], 'coupon_code' => $priced['coupon_code'],
                    'shipping_address' => $shipping, 'billing_address' => $billing,
                    'fraud_score' => $decision->score, 'fraud_decision' => $decision->decision,
                    'expires_at' => (new DateTimeImmutable("+{$ttl} minutes"))->format('Y-m-d H:i:sP'),
                    'ip_address' => $client?->ip, 'country_code' => $client?->country, 'timezone' => $client?->timezone,
                ]);
            });
        } catch (\Throwable $e) {
            $this->reservations->release('order', $id); // never leave stock held for a checkout that does not exist
            throw $e;
        }

        return $this->checkouts->find($id);
    }

    /** Payment methods the shopper can use for this checkout's market and amount. @return list<array<string, mixed>> */
    public function paymentOptions(string $checkoutId): array
    {
        $checkout = $this->require($checkoutId);
        $out = [];
        foreach ($this->providers->all() as $p) {
            $fits = $p['is_enabled'] && in_array($checkout['market_code'], $p['markets'], true)
                && ($p['min_amount'] === null || BigDecimal::of($checkout['total'])->isGreaterThanOrEqualTo($p['min_amount']))
                && ($p['max_amount'] === null || BigDecimal::of($checkout['total'])->isLessThanOrEqualTo($p['max_amount']));
            $fits && $out[] = ['provider' => $p['code'], 'name' => ProviderCatalog::NAMES[$p['code']] ?? $p['code'], 'mode' => $p['mode']];
        }

        return $out;
    }

    /**
     * Starts (or resumes) a payment for the checkout with one provider. Switching provider cancels the one still open, so two
     * methods can never both end up charged for the same checkout.
     *
     * @return array<string, mixed>
     */
    public function pay(string $checkoutId, string $provider, ?string $returnUrl, ?string $cancelUrl, ?string $userId, ?ClientContext $client = null): array
    {
        $checkout = $this->require($checkoutId);
        $checkout['status'] === 'open' || throw new CheckoutError('This checkout can no longer be paid.', 'CHECKOUT_CLOSED');
        new DateTimeImmutable($checkout['expires_at']) > new DateTimeImmutable || throw new CheckoutError('This checkout has expired. Please start again.', 'CHECKOUT_EXPIRED');

        foreach ($this->paymentRows->forPayable('checkout', $checkoutId) as $other) {
            if ($other['provider'] !== $provider && S::isOpen($other['status'])) {
                $this->payments->cancel($other['id'], 'The shopper chose another payment method.');
            }
        }

        $ttl = (int) config('checkout.ttl_minutes', 30);
        $result = $this->payments->start(new PaymentRequest(
            $provider, 'checkout', $checkoutId, new Money($checkout['total'], $checkout['currency']), $checkout['market_code'],
            "checkout:{$checkoutId}:{$provider}", $checkout['email'], 'Order '.strtoupper(substr($checkoutId, -8)), $returnUrl, $cancelUrl,
            ['checkout_id' => $checkoutId],
        ), $client, $userId);

        $this->reservations->extend('order', $checkoutId, $ttl); // the shopper is on the payment page: keep the stock

        return $result;
    }

    /** Gives the stock back and closes a checkout nobody paid. */
    public function cancel(string $checkoutId, ?string $userId): void
    {
        $checkout = $this->require($checkoutId);
        ($userId === null || $checkout['user_id'] === $userId) || throw new CheckoutError('That checkout does not exist.', 'CHECKOUT_NOT_FOUND');
        $checkout['status'] === 'open' || throw new CheckoutError('This checkout can no longer be cancelled.', 'CHECKOUT_CLOSED');
        $this->abandon($checkoutId, 'cancelled', 'Cancelled by the shopper.');
    }

    /**
     * Closes checkouts whose time ran out. A checkout with money still in flight waits (the payment reconciler settles it)
     * until the grace period passes: giving stock back while a charge may still land would sell it twice.
     */
    public function expireDue(?DateTimeImmutable $now = null, int $batch = 200): int
    {
        $now ??= new DateTimeImmutable;
        $grace = (int) config('checkout.expiry_grace_minutes', 60);
        $done = 0;
        foreach ($this->checkouts->dueForExpiry($now, $batch) as $checkout) {
            $inFlight = array_filter($this->paymentRows->forPayable('checkout', $checkout['id']), static fn (array $p) => S::isOpen($p['status']) && $p['provider_reference'] !== null);
            if ($inFlight !== [] && new DateTimeImmutable($checkout['expires_at']) > $now->modify("-{$grace} minutes")) {
                continue;
            }
            $this->abandon($checkout['id'], 'expired', 'The shopper did not pay in time.');
            $done++;
        }

        return $done;
    }

    /** @return array<string, mixed> */
    public function require(string $checkoutId): array
    {
        return $this->checkouts->find($checkoutId) ?? throw new CheckoutError('That checkout does not exist.', 'CHECKOUT_NOT_FOUND');
    }

    private function abandon(string $checkoutId, string $status, string $why): void
    {
        $this->checkouts->atomically(function () use ($checkoutId, $status, $why): void {
            $row = $this->checkouts->lock($checkoutId);
            if ($row === null || $row['status'] !== 'open') {
                return; // paid or settled meanwhile
            }
            foreach ($this->paymentRows->forPayable('checkout', $checkoutId) as $p) {
                S::isOpen($p['status']) && $this->payments->cancel($p['id'], $why);
            }
            $this->checkouts->update($checkoutId, ['status' => $status]);
            $this->checkouts->addNote($checkoutId, $status, $why);
            $this->reservations->release('order', $checkoutId);
        });
    }

    /** @param list<array{product_id: string, quantity: string, by_roll: bool}> $items @return list<array{product_id: string, quantity: string}> */
    private function reservable(array $items): array
    {
        $known = $this->products->find(array_column($items, 'product_id'));

        return array_map(static fn (array $i) => [
            'product_id' => $i['product_id'],
            'quantity' => $i['by_roll'] ? (string) $known[$i['product_id']]['sales']->metersForRolls((int) $i['quantity']) : $i['quantity'],
        ], $items);
    }

    /** @param array<string, mixed> $address */
    private function assertAddress(array $address): void
    {
        foreach (self::ADDRESS_REQUIRED as $field) {
            (isset($address[$field]) && is_string($address[$field]) && trim($address[$field]) !== '') || throw new CheckoutError("The address needs a {$field}.", 'INVALID_ADDRESS');
        }
        strlen((string) $address['country_code']) === 2 || throw new CheckoutError('The country must be a two-letter code.', 'INVALID_ADDRESS');
    }
}
